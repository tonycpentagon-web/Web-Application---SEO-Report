from __future__ import annotations
import statistics
from engine import ExtractionError

def extract_pdf(engine, path):
    import pymupdf
    document = pymupdf.open(path)
    try:
        if document.needs_pass: raise ExtractionError("This PDF is password-protected. Upload an unprotected copy.")
        total = document.page_count
        if total > int(engine.options.get("max_pages", 500)): raise ExtractionError(f"PDF has {total} pages and exceeds the configured page limit. Increase the limit for this trusted file.")
        engine.result["metadata"].update({"page_count": total, "document_properties": document.metadata, "outline": document.get_toc()})
        for index in range(total):
            page = document[index]
            source = {"page": index + 1}
            engine.progress(f"Processing page {index + 1} of {total}", index, total)
            unit = {"page": index + 1, "width": page.rect.width, "height": page.rect.height, "status": "processed", "ocr_required": False}
            engine.result["units"].append(unit)
            try:
                full = page.get_text("text", sort=False)
                engine.add("text", full, {"reading_order": "original_text_stream"}, source)
                blocks = page.get_text("dict")["blocks"]
                sizes = [span["size"] for block in blocks if block["type"] == 0 for line in block["lines"] for span in line["spans"]]
                median = statistics.median(sizes) if sizes else 10
                text_blocks = []
                for number, block in enumerate(blocks):
                    location = {**source, "block": number, "bbox": list(block["bbox"])}
                    if block["type"] == 0:
                        text = "\n".join("".join(span["text"] for span in line["spans"]) for line in block["lines"])
                        text_blocks.append((block["bbox"], text))
                        engine.add("text_block", text, {"lines": block["lines"]}, location)
                        if text.strip() and len(text) < 220 and (any(span["size"] > median * 1.2 for line in block["lines"] for span in line["spans"]) or (text.strip().isupper() and len(text.split()) <= 8 and len(text) < 100)):
                            engine.add("heading", text, {"detected_by": "font_size_or_uppercase", "review_required": True}, location, "inferred")
                    elif block["type"] == 1:
                        metadata = {"width": block["width"], "height": block["height"], "bbox": list(block["bbox"]), "context_text": full}
                        above = [b for b in blocks if b["type"] == 0 and b["bbox"][3] <= block["bbox"][1] + 5 and any(span["text"].strip() for line in b["lines"] for span in line["spans"])]
                        if above:
                            nearest = max(above, key=lambda b: b["bbox"][3])
                            metadata["associated_heading"] = "\n".join("".join(span["text"] for span in line["spans"]) for line in nearest["lines"]).strip()
                            metadata["heading_bbox"] = list(nearest["bbox"])
                            metadata["heading_association"] = "nearest_preceding_text_block; verify against source"
                        asset = engine.asset(block["image"], block.get("ext", "png"), "image", location, metadata)
                        # Every occurrence stays separate, including repeated screenshots/logos.
                        if block["width"] >= 100 and block["height"] >= 35:
                            engine.progress(f"Reading embedded image on page {index + 1}", index, total)
                            engine.ocr(block["image"], {**location, "asset_path": asset["path"]})
                links = page.get_links()
                for link in links:
                    if link.get("uri"): engine.add("url", link["uri"], {"url": link["uri"], "kind": "hyperlink"}, {**source, "bbox": list(link["from"])}, enrich=False)
                try:
                    tables = page.find_tables()
                    strategy = "lines"
                    if not tables.tables:
                        tables = page.find_tables(vertical_strategy="text", horizontal_strategy="text")
                        strategy = "text_geometry"
                    for number, table in enumerate(tables.tables):
                        parser_rows = table.extract()
                        cell_textpage = page.get_textpage()
                        # Preserve native glyph order (underscores/ligatures) instead of
                        # the table parser's reconstructed words. Keep both representations.
                        cell_boxes = [row.cells for row in table.rows]
                        rows = []
                        for row_index, boxes in enumerate(cell_boxes):
                            row = []
                            for column, box in enumerate(boxes):
                                original = parser_rows[row_index][column]
                                native = page.get_textbox(box, textpage=cell_textpage) if box is not None else None
                                # Blank cells are source geometry, not permission to copy
                                # neighboring text whose glyph bounding boxes overlap a band.
                                row.append(native if original is not None and str(original).strip() and native else original)
                            rows.append(row)
                        if len(rows) < 2 or max((len(row) for row in rows), default=0) < 2: continue
                        engine.add("table", "\n".join("\t".join("" if v is None else v for v in row) for row in rows),
                                   {"rows": rows, "parser_rows": parser_rows, "cell_bboxes": cell_boxes, "headers": rows[0] if not table.header.external and rows else table.header.names, "parser_headers": table.header.names, "cell_text_strategy": "native_clip_with_parser_fallback", "strategy": strategy, "review_required": strategy != "lines"},
                                   {**source, "table": number + 1, "bbox": list(table.bbox)}, "native" if strategy == "lines" else "ambiguous")
                        if strategy != "lines":
                            engine.warning("table_geometry", "A table was inferred from text positioning. Verify its column boundaries.", {**source, "table": number + 1})
                except Exception as error:
                    # Independent parser fallback; the source PDF and page rendering remain available.
                    try:
                        import pdfplumber
                        with pdfplumber.open(path) as fallback:
                            rows_list = fallback.pages[index].extract_tables()
                        if not rows_list:
                            raise ValueError("No fallback table detected")
                        for number, rows in enumerate(rows_list):
                            text = "\n".join("\t".join("" if v is None else str(v) for v in row) for row in rows)
                            engine.add("table", text, {"rows": rows, "strategy": "pdfplumber_fallback", "review_required": True}, {**source, "table": number + 1}, "ambiguous")
                        engine.warning("table_parser_fallback", "The primary table parser failed; fallback table boundaries require review.", source)
                    except Exception:
                        engine.error("table_failed", "Table extraction failed (" + type(error).__name__ + "); page text, image evidence and original PDF are retained.", source)
                        unit["status"] = "partial"
                scale = min(1.8, 2500 / max(page.rect.width, page.rect.height))
                preview = page.get_pixmap(matrix=pymupdf.Matrix(scale, scale), alpha=False).tobytes("png")
                engine.asset(preview, "png", "page_preview", source, {"width": round(page.rect.width * scale), "height": round(page.rect.height * scale)})
                if len("".join(full.split())) < 40:
                    unit["ocr_required"] = True
                    engine.progress(f"OCR fallback for page {index + 1} of {total}", index, total)
                    engine.ocr(preview, {**source, "kind": "scanned_page"})
            except ExtractionError as error:
                unit["status"] = "partial"
                engine.error("page_limit", str(error), source)
            except Exception as error:
                unit["status"] = "partial"
                engine.error("page_failed", "Page extraction failed (" + type(error).__name__ + "); original PDF retained.", source)
            engine.progress(f"Processed page {index + 1} of {total}", index + 1, total)
    finally:
        document.close()
