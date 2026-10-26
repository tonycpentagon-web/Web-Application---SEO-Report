from __future__ import annotations
import csv, io, json
from engine import ExtractionError

def extract_csv(engine,path):
    from charset_normalizer import from_bytes
    data=path.read_bytes();encoding="utf-8-sig"
    try: text=data.decode(encoding)
    except UnicodeDecodeError:
        detected=from_bytes(data).best()
        if detected is None: raise ExtractionError("Unable to detect CSV encoding without losing bytes.")
        encoding=detected.encoding;text=data.decode(encoding,errors="strict")
        engine.warning("encoding_detected","CSV encoding was detected as "+encoding+". Verify non-ASCII text against the original.")
    try: dialect=csv.Sniffer().sniff(text[:32768],delimiters=",;\t|")
    except csv.Error:
        dialect=csv.excel;engine.warning("csv_dialect","CSV delimiter could not be determined with confidence; comma parsing was used.")
    reader=csv.reader(io.StringIO(text,newline=""),dialect=dialect);rows=[];ends=[];cells=0
    for row in reader:
        cells+=len(row)
        if cells>int(engine.options.get("max_cells",250000)): raise ExtractionError("CSV exceeds the configured cell limit; increase it for this trusted file.")
        rows.append(row);ends.append(reader.line_num)
    engine.result["metadata"].update({"encoding":encoding,"row_count":len(rows),"delimiter":dialect.delimiter,"worksheet_count":1})
    engine.add("text",text,{"encoding":encoding},{"worksheet":"CSV"})
    engine.add("table",text,{"rows":rows,"headers":rows[0] if rows else [],"physical_line_ends":ends},{"worksheet":"CSV"})
    engine.result["units"].append({"worksheet":"CSV","status":"processed","rows":len(rows),"columns":max((len(r) for r in rows),default=0)})
    engine.progress(f"Parsed {len(rows)} CSV rows",1,1)

def extract_xls(engine,path):
    import xlrd
    workbook=xlrd.open_workbook(str(path),on_demand=True)
    try:
        engine.result["metadata"].update({"worksheet_count":workbook.nsheets,"sheet_names":workbook.sheet_names(),"legacy_format":True})
        cells=0
        for number in range(workbook.nsheets):
            sheet=workbook.sheet_by_index(number);source={"worksheet":sheet.name};rows=[]
            cells+=sheet.nrows*sheet.ncols
            if cells>int(engine.options.get("max_cells",250000)):raise ExtractionError("Legacy workbook exceeds the configured cell limit.")
            engine.progress(f"Processing legacy worksheet {number+1} of {workbook.nsheets}",number,workbook.nsheets)
            for r in range(sheet.nrows):
                row=[]
                for c in range(sheet.ncols):
                    cell=sheet.cell(r,c);value=cell.value
                    if cell.ctype==xlrd.XL_CELL_DATE:
                        value=xlrd.xldate_as_datetime(value,workbook.datemode).isoformat()
                    row.append(value)
                    engine.add("cell",str(value),{"value":value,"raw_value":cell.value,"cell_type":cell.ctype},{**source,"row":r+1,"column":c+1})
                rows.append(row)
            text="\n".join("\t".join(str(v) for v in row) for row in rows)
            engine.add("worksheet_text",text,{},source);engine.add("table",text,{"rows":rows,"headers":rows[0] if rows else []},source)
            engine.result["units"].append({**source,"status":"processed","rows":sheet.nrows,"columns":sheet.ncols})
            engine.progress("Processed legacy worksheet "+sheet.name,number+1,workbook.nsheets)
        engine.warning("legacy_excel_limit","Legacy XLS cell/date values are extracted. Formula definitions, chart layouts and embedded images are not decoded by xlrd; the complete original OLE workbook is retained for review.")
    finally: workbook.release_resources()

def extract_image(engine,path):
    from PIL import Image
    with Image.open(path) as image:
        image.verify()
    with Image.open(path) as image:
        if image.width*image.height>40000000: raise ExtractionError("Image exceeds the 40-megapixel safety limit.")
        engine.result["metadata"].update({"width":image.width,"height":image.height,"image_format":image.format,"frames":getattr(image,"n_frames",1)})
        if getattr(image,"n_frames",1)>1:engine.warning("animated_image","Only the first frame is OCR-read; all frames remain in the original image.",incomplete=True)
    raw=path.read_bytes();asset=engine.asset(raw,path.suffix.lstrip("."),"image",{"page":1},{"original_image":True})
    engine.result["units"].append({"page":1,"status":"processed","ocr_required":True})
    engine.progress("Reading image text with OCR",0,1);engine.ocr(raw,{"page":1,"asset_path":asset["path"]})
    engine.progress("Processed image",1,1)
