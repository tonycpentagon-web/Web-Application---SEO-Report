"""Deterministic, source-grounded SEO classification and normalization.
No client names, filenames, fixture values or external model services influence rules.
"""
import re, json, hashlib, calendar
from datetime import date
from collections import defaultdict
from decimal import Decimal, InvalidOperation
VERSION = "pentagon-normalizer/1.0"
KINDS = ["report_metrics","traffic_channels","device_metrics","query_metrics","landing_page_metrics","keyword_rankings","seo_activities","ai_visibility_evidence","event_metrics","unclassified"]
SECTIONS = ["overview","website_analytics","search_console","keyword_rankings","seo_activities","ai_visibility","key_events","unclassified"]
def clean(value): return re.sub(r"\s+"," ",str("" if value is None else value)).strip()
def key(value): return re.sub(r"[^a-z0-9]","",clean(value).lower())
def number(raw, unit="count"):
    value=clean(raw)
    if unit=="seconds":
        if not re.fullmatch(r"\d{1,3}:\d{2}(?::\d{2})?",value):raise ValueError("Invalid duration")
        parts=[int(v) for v in value.split(":")]
        if any(v>=60 for v in (parts[1:] if len(parts)==3 else parts[-1:])):raise ValueError("Invalid duration")
        return sum(v*(60**i) for i,v in enumerate(reversed(parts)))
    value=value.removesuffix("%").strip()
    if not re.fullmatch(r"(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?",value):raise ValueError("Ambiguous or invalid number")
    n=Decimal(value.replace(",",""))
    if unit=="percent" and not 0<=n<=100:raise ValueError("Percentage outside range")
    if unit=="count" and n!=int(n):raise ValueError("Count must be an integer")
    return int(n) if n==int(n) else float(n)
def rank(raw):
    text=clean(raw);m=re.fullmatch(r"(\d+)(?:st|nd|rd|th)\s+Page",text,re.I)
    if m:return {"group":"page_"+m[1],"page_group":int(m[1]),"exact_position":None}
    if re.fullmatch(r"(?:not\s*ranked|unranked|n/?a|[-—])",text,re.I):return {"group":"not_ranked","page_group":None,"exact_position":None}
    if re.fullmatch(r"#?[1-9]\d*",text):return {"group":"exact_position","page_group":None,"exact_position":int(text.lstrip("#"))}
    return {"group":"unknown","page_group":None,"exact_position":None}
def classify(text, content):
    table_text="\n".join(c.get("text","")+"\n"+"\n".join("\t".join(str(v or "") for v in row) for row in c.get("data",{}).get("rows",[])[:10]) for c in content if c["type"]=="table")
    t=(text+"\n"+table_text).lower();labels=[]
    cues={
      "ga4":r"\bga4\b|google analytics|total users|new users|session duration|traffic acquisition|active users",
      "search_console":r"google search console|impressions[\s\S]{0,200}(?:ctr|clicks)|(?:ctr|clicks)[\s\S]{0,200}impressions",
      "keyword_ranking":r"(?:^|\n)\s*(?:(?:extra|primary|additional)\s+)?keywords?\s*[,|\t\n]+\s*(?:rank|ranking|position)|\b(?:1st|2nd|3rd) page\b",
      "seo_work":r"article submissions?|profile creation|social bookmarking|ppt submissions?|guest posts?",
      "ai_visibility":r"ai visibility|ai search|ai overview|search visibility evidence",
      "key_events":r"event\s*name[\s\S]{0,80}event\s*count|key events|event,?count",
      "backlinks":r"backlinks?|referring domains|link building report",
      "technical_audit":r"technical seo|crawl errors|robots\.txt|canonical issues|technical audit"
    }
    for kind,pattern in cues.items():
        if re.search(pattern,t):labels.append({"type":kind,"confidence":"rule_based","rule":pattern})
    keyword_headers=any(any(key(v) in ("keyword","keywords","primarykeywords","extrakeywords","additionalkeywords") for v in row) and any(key(v) in ("rank","ranking","position","rankinglabel") for v in row) for c in content if c["type"]=="table" for row in c.get("data",{}).get("rows",[])[:8])
    if keyword_headers and not any(v["type"]=="keyword_ranking" for v in labels):labels.append({"type":"keyword_ranking","confidence":"rule_based","rule":"Semantic keyword and rank table headers"})
    return labels or [{"type":"other_seo","confidence":"unclassified","rule":"No supported semantic cues"}]

class Normalizer:
 def __init__(self, manifest):
    self.period=manifest["report"];self.documents=manifest["documents"]
    self.records=[];self.warnings=[];self.classifications=[];self.consumed=set();self.identities=set()
 def warn(self,code,message,doc=None,source=None,record_key=None,severity="warning"):
    self.warnings.append({"code":code,"message":message,"document_id":doc["id"] if doc else None,"source":source or {},"record_key":record_key,"severity":severity})
 def ref(self,doc,content=None,asset=None,raw=None,source=None):
    src=source or (content or {}).get("source") or (asset or {}).get("source") or {}
    return {"document_id":doc["id"],"extraction_run_id":doc["extraction_run_id"],"content_id":content["id"] if content else None,"asset_id":asset["id"] if asset else None,"source":src,"quote":(content or {}).get("text",""),"raw_value":raw}
 def add(self,kind,section,doc,identity,data,refs,raw=None,confidence="source",notes=None):
    stable=hashlib.sha256((doc["id"]+"|"+kind+"|"+str(identity)).encode()).hexdigest()
    if (kind,stable) in self.identities:return None
    self.identities.add((kind,stable))
    record={"kind":kind,"section":section,"source_key":stable,"data":data,"raw":raw if raw is not None else data,"sources":refs,"confidence":confidence,"notes":notes or []}
    self.records.append(record)
    for ref in refs:
        if ref.get("content_id"):self.consumed.add(ref["content_id"])
    return record
 def metric(self,doc,c,name,value,unit,namespace,identity=None,raw=None):
    try:n=number(value,unit)
    except ValueError as e:
        self.warn("invalid_number",name+": "+str(e),doc,c.get("source"));return
    return self.add("report_metrics","key_events" if namespace=="events" else "search_console" if namespace=="gsc" else "website_analytics",doc,identity or name,
        {"metric_key":namespace+"."+name,"label":name.replace("_"," "),"value":n,"unit":unit,"definition":namespace,"granularity":"reported_period_total"},[self.ref(doc,c,raw=raw or value)],raw or value,"ocr" if c.get("confidence")=="ocr" or c["type"]=="ocr_text" else "source")
 def canonical(self,doc):
    content=doc["content"]
    native=[c for c in content if c["type"]=="text" and c.get("data",{}).get("reading_order")=="original_text_stream"]
    if native:return native
    preferred=[c for c in content if c["type"] in ("text","worksheet_text","paragraph","heading")]
    return preferred or [c for c in content if c["type"]=="ocr_text"]
 def dates(self,doc,cs):
    # Do not promote metadata, activity/chart dates or arbitrary narrative dates into report coverage.
    atom=r"(?:[A-Za-z]{3,9}\s*\d{1,2},?\s*20\d{2}|\d{1,2}\s*[A-Za-z]{3,9}\s*20\d{2}|20\d{2}-\d{2}-\d{2})"
    pattern=re.compile(r"(?P<start>"+atom+r")\s*(?:to|[-\u2013\u2014])\s*(?P<end>"+atom+r")",re.I)
    months={n.lower():i for i in range(1,13) for n in (calendar.month_name[i],calendar.month_abbr[i])};months["sept"]=9
    def parse(raw):
      if re.fullmatch(r"20\d{2}-\d{2}-\d{2}",raw):return date.fromisoformat(raw).isoformat()
      us=re.fullmatch(r"([A-Za-z]{3,9})\s*(\d{1,2}),?\s*(20\d{2})",raw)
      if us:raw=us[2]+" "+us[1]+" "+us[3]
      m=re.fullmatch(r"(\d{1,2})\s*([A-Za-z]{3,9})\s*(20\d{2})",raw)
      return date(int(m[3]),months[m[2].lower()],int(m[1])).isoformat()
    seen=set()
    for c in cs:
      if c['type'] not in ('text','worksheet_text','heading','paragraph','ocr_text'):continue
      lines=c.get('text','').replace('\u00a0',' ').splitlines()
      for index,line in enumerate(lines):
        context=(lines[index-1]+' ' if index else '')+line
        if re.search(r"\b(?:created|creation|uploaded|upload date|generated (?:on|at)|printed|downloaded|captured|screenshot (?:date|taken|timestamp)|timestamp|chart(?: date| range| axis)?|x.axis|daily trend|activity date|task date|comparison|previous period|prior period)\b",context,re.I):continue
        labelled=bool(re.search(r"report(?:ing)?\s*(?:period|date|range)|date range|period covered|coverage",context,re.I))
        if not labelled and re.sub(r'\barrow_(?:drop_down|drop_up)\b','',pattern.sub('',line)).strip(' \t:;,()[]'):continue
        for m in pattern.finditer(line):
          try:a=parse(m['start']);b=parse(m['end'])
          except (ValueError,KeyError,TypeError):continue
          if a>b or (a,b) in seen:continue
          seen.add((a,b))
          if a==self.period['start_date'] and b==self.period['end_date']:continue
          ocr=c['type']=='ocr_text' or c.get('confidence') in ('ocr','uncertain','low','inferred','ambiguous')
          outside=a<self.period['start_date'] or b>self.period['end_date']
          code='uncertain_period' if ocr else 'date_range_mismatch' if outside else 'partial_period_coverage'
          self.warn(code,f"Source reports {a} to {b}. Printed dates are retained; "+('OCR dates require explicit source assignment.' if ocr else 'coverage falls outside the selected report.' if outside else 'coverage is partial within the selected month.'),doc,{**c.get('source',{}),'line':index+1})
    return [{"start":a,"end":b} for a,b in sorted(seen)]
 def scalar_metrics(self,doc,cs,types):
    aliases=[
     ("total_users",["total users"],"count","ga4"),("active_users",["active users"],"count","ga4"),("users",["users"],"count","ga4"),("new_users",["new users"],"count","ga4"),
     ("views",["page views","pageviews","views"],"count","ga4"),("sessions",["total sessions","sessions"],"count","ga4"),
     ("engaged_sessions",["engaged sessions"],"count","ga4"),("engagement_rate",["engagement rate"],"percent","ga4"),("bounce_rate",["bounce rate"],"percent","ga4"),
     ("average_engagement_time",["average engagement time per active user","average engagement time"],"seconds","ga4"),
     ("average_session_duration",["average session duration","avg. session duration","avg session duration"],"seconds","ga4"),
     ("clicks",["total clicks","clicks"],"count","gsc"),("impressions",["total impressions","impressions"],"count","gsc"),
     ("ctr",["site ctr","average ctr","avg ctr","ctr"],"percent","gsc"),("average_position",["average position","avg position"],"position","gsc")
    ]
    for c in cs:
      for name,labels,unit,ns in aliases:
        if (ns=="ga4" and "ga4" not in types) or (ns=="gsc" and "search_console" not in types):continue
        pattern=r"(?im)^\s*(?:"+"|".join(re.escape(v) for v in labels)+r")\s*[:\t,]?\s*([0-9][0-9,:.%]*)\s*(?=\n|$)"
        for m in re.finditer(pattern,c.get("text","")):
          self.metric(doc,c,name,m[1],unit,ns,f"{name}|{c.get('source')}|{m.start()}",m[0].strip())
 def text_rows(self,c,header,columns):
    lines=[line.strip() for line in c.get("text","").splitlines() if line.strip()]
    start=next((i for i,l in enumerate(lines) if re.fullmatch(header,l,re.I)),None)
    if start is None:return []
    rows=[];i=start+1
    while i<len(lines):
      label=lines[i]
      if label.lower().startswith("grand total"):break
      # Header bands and chart/narrative text are not accepted as data rows.
      candidates=[];j=i+1
      if label.startswith(("https://","http://")):
        while j<len(lines) and not re.fullmatch(r"[\d,]+(?:\.\d+)?%?",lines[j]) and len(candidates)<3:
          candidates.append(lines[j]);j+=1
        label=label+("".join(candidates) if candidates else "")
      values=lines[j:j+columns]
      try:
        if len(values)!=columns:raise ValueError
        parsed=[number(v,"percent" if "%" in v else "count") for v in values]
        if re.fullmatch(r"[\d,.%]+",label):raise ValueError
      except ValueError:i+=1;continue
      rows.append((label,values,{"start_line":i+1,"end_line":j+columns,"wrapped_label":bool(candidates)}));i=j+columns
      if rows and i<len(lines) and re.match(r"RESULT ANALYSIS|PAGEWISE",lines[i],re.I):break
    return rows
 def analytics(self,doc,cs,types):
    for c in cs:
      if "ga4" in types:
       for label,values,loc in self.text_rows(c,r"Session default|Session default channel group|Channel(?: group)?|Traffic channel",2):
        # A split channel header ('channel group') will be skipped until a label/numeric row.
        if label.startswith(("http://","https://")):continue
        data={"channel":label,"total_users":number(values[0]),"new_users":number(values[1]),"definition":"ga4"}
        self.add("traffic_channels","website_analytics",doc,f"{c['source']}|{loc['start_line']}",data,[self.ref(doc,c,raw=[label,*values],source={**c["source"],**loc})],[label,*values])
        if key(label) in ("organicsearch","direct"):
          self.metric(doc,c,"organic_search_users" if key(label)=="organicsearch" else "direct_users",values[0],"count","ga4",f"channel-metric|{key(label)}|{c['source']}",[label,*values])
      if "search_console" in types:
       for label,values,loc in self.text_rows(c,r"Device Category|Device",2):
        if label.startswith(("http://","https://")):continue
        self.add("device_metrics","search_console",doc,f"{c['source']}|{loc['start_line']}",{"device":label,"clicks":number(values[0]),"impressions":number(values[1]),"definition":"gsc"},[self.ref(doc,c,raw=[label,*values],source={**c["source"],**loc})],[label,*values])
       header=re.search(r"(?im)^\s*(?:Landing Page|Page URL|Landing page URL)\s*\n(?P<columns>(?:[^\n]+\n){1,6})",c.get("text",""))
       column_text=header["columns"].lower() if header else ""
       impression_first="impressions" in column_text and "clicks" in column_text and column_text.index("impressions")<column_text.index("clicks")
       for label,values,loc in self.text_rows(c,r"Landing Page|Page URL|Landing page URL",3):
        if not label.startswith(("https://","http://")):continue
        r=self.add("landing_page_metrics","search_console",doc,f"{c['source']}|{loc['start_line']}",{"url":label,"clicks":number(values[1] if impression_first else values[0]),"impressions":number(values[0] if impression_first else values[1]),"ctr":number(values[2],"percent"),"ctr_unit":"percent","definition":"gsc"},[self.ref(doc,c,raw=[label,*values],source={**c["source"],**loc})],[label,*values],"inferred" if loc["wrapped_label"] else "source")
        if loc["wrapped_label"]:self.warn("wrapped_url","A URL wraps across source lines. Joined text requires comparison with the original.",doc,{**c["source"],**loc},r["source_key"] if r else None)
 def structured(self,doc,types):
    for c in doc["content"]:
      if c["type"]!="table":continue
      rows=c.get("data",{}).get("rows",[])
      for header_index,header in enumerate(rows[:8]):
        cols={key(v):i for i,v in enumerate(header) if v is not None};kind=None;fields={}
        def col(*names):return next((cols[n] for n in names if n in cols),None)
        if col("channel","channelgroup","sessiondefaultchannelgroup") is not None and col("totalusers","users") is not None:
          kind="traffic_channels";fields={"channel":col("channel","channelgroup","sessiondefaultchannelgroup"),"total_users":col("totalusers","users"),"new_users":col("newusers")}
        elif col("device","devicecategory") is not None and col("clicks") is not None:
          kind="device_metrics";fields={"device":col("device","devicecategory"),"clicks":col("clicks"),"impressions":col("impressions")}
        elif col("query","topqueries","searchquery") is not None and col("clicks") is not None:
          kind="query_metrics";fields={"query":col("query","topqueries","searchquery"),"clicks":col("clicks"),"impressions":col("impressions"),"ctr":col("ctr"),"position":col("position","averageposition")}
        elif col("landingpage","url","pageurl") is not None and col("clicks","urlclicks") is not None:
          kind="landing_page_metrics";fields={"url":col("landingpage","url","pageurl"),"clicks":col("clicks","urlclicks"),"impressions":col("impressions"),"ctr":col("ctr","urlctr")}
        elif col("eventname","event") is not None and col("eventcount","count") is not None:
          kind="event_metrics";fields={"event_name":col("eventname","event"),"count":col("eventcount","count")}
        elif col("category","activitycategory","activitytype") is not None and col("url","submissionurl") is not None:
          kind="seo_activities";fields={"category":col("category","activitycategory","activitytype"),"url":col("url","submissionurl"),"description":col("description","activitydescription"),"status":col("status"),"activity_date":col("date","activitydate")}
        elif col("metric","metricname") is not None and col("value") is not None:
          kind="metric_values";fields={"metric":col("metric","metricname"),"value":col("value")}
        if not kind:continue
        # PDFs use their canonical native stream; structured rows support CSV/Excel/Word layouts.
        if any(n["type"]=="text" and n.get("data",{}).get("reading_order") for n in doc["content"]):break
        for i,row in enumerate(rows[header_index+1:],header_index+1):
          data={}
          if kind=="metric_values":
            label=key(row[fields["metric"]]) if fields["metric"]<len(row) else ""
            metrics={"totalusers":("total_users","count","ga4"),"newusers":("new_users","count","ga4"),"views":("views","count","ga4"),"sessions":("sessions","count","ga4"),"engagedsessions":("engaged_sessions","count","ga4"),"engagementrate":("engagement_rate","percent","ga4"),"bouncerate":("bounce_rate","percent","ga4"),"averageposition":("average_position","decimal","gsc"),"clicks":("clicks","count","gsc"),"impressions":("impressions","count","gsc"),"ctr":("ctr","percent","gsc"),"totalevents":("reported_total","count","events")}
            spec=metrics.get(label)
            if spec and (spec[2]=="events" or ("ga4" if spec[2]=="ga4" else "search_console") in types) and fields["value"]<len(row):self.metric(doc,c,spec[0],row[fields["value"]],spec[1],spec[2],f"metric-table|{c['source']}|{i}",row)
            continue
          try:
            for field,index in fields.items():
              value=row[index] if index is not None and index<len(row) else None
              data[field]=clean(value) if field in ("channel","device","query","url","event_name","category","description","status","activity_date") else number(value,"percent" if field=="ctr" else "decimal" if field=="position" else "count") if value not in (None,"") else None
            if clean(next(iter(data.values()))).lower() in ("grand total","total","total events"):
              if kind=="event_metrics" and data.get("count") is not None:self.metric(doc,c,"reported_total",data["count"],"count","events",f"events-total|{c['source']}|{i}",row)
              continue
            if not next(iter(data.values())):continue
          except ValueError:self.warn("invalid_number","Invalid structured table number; raw row retained.",doc,c["source"]);continue
          if kind=="seo_activities":
            data={k:v for k,v in data.items() if v not in (None,"")};data.update({"count":1,"evidence_status":"listed_in_source","live_link_verified":False})
            raw_date=data.get("activity_date")
            if raw_date:
              try:data["activity_date"]=date.fromisoformat(raw_date).isoformat()
              except ValueError:data["activity_date"]=None;self.warn("invalid_date","Activity date is ambiguous; original row retained.",doc,c["source"])
          if kind=="event_metrics":data["classification"]="reported_event_not_verified_lead"
          self.add(kind,"key_events" if kind=="event_metrics" else "website_analytics" if kind=="traffic_channels" else "seo_activities" if kind=="seo_activities" else "search_console",doc,f"table|{c['source']}|{i}",data,[self.ref(doc,c,raw=row,source={**c["source"],"row":i})],row)
        break
 def keywords(self,doc):
    has_extra=any(re.search(r"(?:extra|additional|secondary)\s+keywords?",str(c.get("data",{}).get("rows",[])),re.I) for c in doc["content"] if c["type"]=="table")
    category="primary" if has_extra else "unspecified";last=None;occurrences=defaultdict(int)
    layout={"serial":0,"keyword":1,"ranking":2,"date":None,"location":None}
    created=[];anchors={}
    for c in doc["content"]:
      if c["type"]!="table":continue
      rows=c.get("data",{}).get("rows",[])
      if not any(any(re.search(r"rank|position|\d(?:st|nd|rd|th) page|not ranked",clean(v),re.I) for v in row) for row in rows):continue
      for i,row in enumerate(rows):
        marker=" ".join(clean(v) for v in row if v)
        if re.fullmatch(r"(?:extra|additional|secondary)\s+keywords?",marker,re.I):
          category="additional";last=None;continue
        if len(row)<2:continue
        headers={key(v):j for j,v in enumerate(row) if v is not None}
        keyword_col=next((headers[n] for n in ("keyword","keywords","primarykeywords","extrakeywords","additionalkeywords","secondarykeywords") if n in headers),None)
        rank_col=next((headers[n] for n in ("rank","ranking","position","rankinglabel","numericalrank") if n in headers),None)
        if keyword_col is not None and rank_col is not None:
          layout={"keyword":keyword_col,"ranking":rank_col,"serial":next((headers[n] for n in ("slno","serialno","no","sno","number") if n in headers),None),"date":next((headers[n] for n in ("date","rankingdate","rankdate") if n in headers),None),"location":next((headers[n] for n in ("location","searchlocation","country","region") if n in headers),None)}
          if any(re.search(r"(?:extra|additional|secondary)\s+keywords?",clean(v),re.I) for v in row):category="additional"
          elif any("primary" in key(v) for v in row):category="primary"
          boxes=c.get("data",{}).get("cell_bboxes",[])
          anchors={field:(boxes[i][index][0]+boxes[i][index][2])/2 for field,index in layout.items() if index is not None and i<len(boxes) and index<len(boxes[i]) and boxes[i][index] is not None}
          last=None;continue
        def cell(field):
          col=layout[field]
          boxes=c.get("data",{}).get("cell_bboxes",[])
          # Merged cells can move text to a different physical column index.
          # Carry semantic x coordinates onto continuation pages, never copy values.
          if field in anchors and i<len(boxes):
            matches=[j for j,box in enumerate(boxes[i]) if box is not None and box[0]<=anchors[field]<=box[2]]
            if len(matches)==1:
              col=matches[0]
              # A keyword cell spanning the empty ranking band is not a rank.
              if field=="ranking" and "keyword" in anchors and boxes[i][col][0]<=anchors["keyword"]<=boxes[i][col][2]:return ""
          return clean(row[col]) if col is not None and col<len(row) else ""
        serial=cell("serial");name=cell("keyword");label=cell("ranking")
        if not name:continue
        if layout["serial"] is not None and not serial and last:
          last["data"]["alternative_labels"].append(name);last["raw"]["fragments"].append(row);last["sources"].append(self.ref(doc,c,raw=row,source={**c["source"],"row":i}))
          if label and not last["data"]["ranking_label"]:
            last["data"].update({"ranking_label":label,**rank(label)});last["confidence"]="inferred"
            self.warn("multiline_keyword_rank","A ranking spans multiple source lines/alternative labels; confirm the association.",doc,c["source"],last["source_key"])
          continue
        if layout["serial"] is not None and not serial.isdigit():continue
        identity_serial=serial if serial else str(c["source"])+"|row|"+str(i)
        occurrences[(category,identity_serial)]+=1
        raw_date=cell("date");ranking_date=None
        if raw_date:
          try:ranking_date=date.fromisoformat(raw_date.split("T")[0]).isoformat()
          except ValueError:self.warn("invalid_date","Ranking date format is ambiguous. The raw table cell is retained.",doc,c["source"])
        data={"keyword":name,"alternative_labels":[],"ranking_label":label or None,**rank(label),"category":category,"source_serial":serial or None,"ranking_date":ranking_date,"search_location":cell("location") or None}
        last=self.add("keyword_rankings","keyword_rankings",doc,f"{category}|{identity_serial}|{occurrences[(category,identity_serial)]}",data,[self.ref(doc,c,raw=row,source={**c["source"],"row":i})],{"fragments":[row]})
        if last:created.append(last)
        if not label:self.warn("missing_value","A keyword source row has no ranking on the same line.",doc,c["source"],last["source_key"] if last else None)
        elif rank(label)["group"]=="unknown":self.warn("unsupported_ranking_label","Unrecognized ranking label retained without inventing a position.",doc,c["source"],last["source_key"] if last else None)
    if not created:
      self.warn("keyword_extraction_empty","A keyword ranking source was detected, but no usable keyword rows were normalized. Inspect table columns and retry generation; the keyword section must not silently disappear.",doc,severity="error")
    if created and any(r["data"]["ranking_date"] is None or r["data"]["search_location"] is None for r in created):
      self.warn("ranking_context_missing","Exact ranking date/location are not reported by some supported source fields; missing values remain null.",doc)
 def activities(self,doc,cs,types):
    categories={"articlesubmission":"article_submission","profilecreation":"profile_creation","guestpost":"guest_post","socialbookmarking":"social_bookmarking","pptsubmission":"ppt_submission","presentationsubmission":"ppt_submission","imagesubmission":"image_submission"}
    category=None;serial=None;fragments=[];start=None;seen=defaultdict(int)
    links=[c for c in doc["content"] if c["type"]=="url" and c.get("data",{}).get("kind")=="hyperlink"]
    def flush(c):
      nonlocal fragments,serial,start
      if not category or not fragments:return
      raw="\n".join(fragments);url="".join(fragments)
      targets=[l["text"] for l in links if l.get("source",{}).get("page")==c.get("source",{}).get("page") and l["text"].startswith(fragments[0])]
      exact=next((t for t in targets if t==url),None)
      unique=list(dict.fromkeys(targets))
      resolved=exact or (unique[0] if len(unique)==1 else url)
      discrepancy=resolved!=url
      seen[(category,serial)]+=1
      refs=[self.ref(doc,c,raw=raw,source={**c.get("source",{}),"line":start})]
      if resolved in targets:
        target=next(l for l in links if l["text"]==resolved and l.get("source",{}).get("page")==c.get("source",{}).get("page"));refs.append(self.ref(doc,target,raw=exact))
      r=self.add("seo_activities","seo_activities",doc,f"{category}|{serial}|{seen[(category,serial)]}",{"category":category,"url":resolved,"displayed_url":raw,"source_serial":serial,"count":1,"evidence_status":"listed_in_source","live_link_verified":False},refs,raw,"source" if not discrepancy and (exact or len(fragments)==1) else "inferred")
      if discrepancy:self.warn("url_source_disagreement","Displayed URL text differs from its unique matching hyperlink target. The target and displayed text are both retained; review before approval.",doc,c.get("source"),r["source_key"] if r else None)
      if len(fragments)>1 and not exact and not discrepancy:self.warn("wrapped_url","A wrapped activity URL has no matching complete hyperlink annotation; review joined text.",doc,c.get("source"),r["source_key"] if r else None)
      fragments=[]
    for c in cs:
      lines=[l.strip() for l in c.get("text","").splitlines() if l.strip()]
      for n,line in enumerate(lines):
        label=key(line).rstrip("s")
        if label in categories:
          flush(c);category=categories[label];serial=None;continue
        if re.fullmatch(r"\d+\.?",line):flush(c);serial=line.rstrip(".");continue
        if line.startswith(("http://","https://")):
          flush(c);fragments=[line];start=n+1
          if serial is None:serial=str(n+1)
        elif fragments and re.fullmatch(r"[^\s]+",line):fragments.append(line)
      flush(c)
    if "backlinks" in types and not any(r["kind"]=="seo_activities" and r["sources"][0]["document_id"]==doc["id"] for r in self.records):
      for c in cs:
       for i,m in enumerate(re.finditer(r"https?://[^\s<>]+",c.get("text",""))):
        self.add("seo_activities","seo_activities",doc,f"backlink|{c['id']}|{i}",{"category":"backlink_reference","url":m[0],"count":1,"evidence_status":"listed_in_source","live_link_verified":False},[self.ref(doc,c,raw=m[0])],m[0])
 def events(self,doc,cs):
    for c in cs:
      lines=[l.strip() for l in c.get("text","").splitlines() if l.strip()]
      for i,line in enumerate(lines[:-1]):
        if re.fullmatch(r"(?:grand\s+total|total events)",line,re.I):
          self.metric(doc,c,"reported_total",lines[i+1],"count","events","events-total",line+" "+lines[i+1]);continue
        if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_. -]{1,100}",line):continue
        if key(line) in ("eventname","eventcount","event","count","grandtotal","total"):continue
        try:value=number(lines[i+1])
        except ValueError:continue
        self.add("event_metrics","key_events",doc,f"{c['source']}|{i}",{"event_name":line,"count":value,"classification":"reported_event_not_verified_lead"},[self.ref(doc,c,raw=[line,lines[i+1]],source={**c.get("source",{}),"line":i+1})],[line,lines[i+1]],"ocr" if c["type"]=="ocr_text" else "source")
 def ai(self,doc,cs):
    page_text={c.get("source",{}).get("page"):c.get("text","") for c in cs}
    for a in doc.get("assets",[]):
      if a.get("type")!="image":continue
      page=a.get("source",{}).get("page");text=page_text.get(page,"")
      if re.search(r"ADDRESSED TO|PREPARED FOR",text,re.I):continue
      heading=a.get("metadata",{}).get("associated_heading")
      if not heading:self.warn("missing_ai_heading","An image has no reliable query heading; retained as unclassified evidence.",doc,a.get("source"));continue
      oc=[c for c in doc["content"] if c["type"]=="ocr_text" and c.get("source",{}).get("page")==page and c.get("source",{}).get("bbox")==a.get("source",{}).get("bbox")]
      ocr="\n".join(c.get("text","") for c in oc);platform=next((p for p in ["Google","ChatGPT","Perplexity","Copilot","Gemini"] if re.search(r"\b"+p+r"\b",ocr,re.I)),None)
      self.add("ai_visibility_evidence","ai_visibility",doc,str(a.get("source")),{"query":clean(heading),"platform":platform,"brand_appearance":"unreviewed","notes":"Screenshot evidence only; no AI ranking, visibility percentage or traffic inferred.","asset_id":a["id"]},[self.ref(doc,asset=a,raw=heading)]+[self.ref(doc,c) for c in oc],{"heading":heading,"ocr_text":ocr},"inferred")
 def validate(self):
    metrics=defaultdict(list)
    for r in self.records:
      if r["kind"]=="report_metrics":metrics[r["data"]["metric_key"]].append(r)
    for name,records in metrics.items():
      values={json.dumps(r["data"]["value"],sort_keys=True) for r in records}
      if len(records)>1:
       for r in records:self.warn("conflicting_metric" if len(values)>1 else "duplicate_metric",name+" has multiple source observations. No value was overwritten or summed.",record_key=r["source_key"])
    totals=[r["data"]["value"] for r in self.records if r["kind"]=="report_metrics" and r["data"]["metric_key"]=="events.reported_total"]
    events=[r for r in self.records if r["kind"]=="event_metrics"]
    if len(totals)==1 and events and sum(r["data"]["count"] for r in events)!=totals[0]:self.warn("event_total_mismatch","Reported event total differs from the sum of event records.")
    if not any(r["kind"]!="unclassified" for r in self.records):self.warn("no_supported_data","No supported normalized data was identified; source evidence is retained.")
 def run(self):
    for doc in self.documents:
      cs=self.canonical(doc);text="\n".join(c.get("text","") for c in cs)
      classification=classify(text,doc["content"]);types={x["type"] for x in classification}
      dates=self.dates(doc,cs);self.classifications.append({"document_id":doc["id"],"labels":classification,"source_dates":dates})
      if doc["status"]=="failed":self.warn("source_extraction_failed","This source failed extraction and has no reliable normalized values. Retry extraction; its original remains retained.",doc)
      if doc["status"]=="partially_extracted":self.warn("partial_extraction","Source extraction is partial. Inspect preserved images and extraction warnings.",doc)
      if any(c.get("confidence")=="ocr" for c in doc["content"]):self.warn("uncertain_ocr","Source includes OCR observations; numeric values and labels require human review.",doc)
      self.scalar_metrics(doc,cs,types);self.analytics(doc,cs,types);self.structured(doc,types)
      if "keyword_ranking" in types:self.keywords(doc)
      if types & {"seo_work","backlinks"}:self.activities(doc,cs,types)
      if "key_events" in types and not any(r["kind"]=="event_metrics" and r["sources"][0]["document_id"]==doc["id"] for r in self.records):self.events(doc,cs)
      if "ai_visibility" in types:self.ai(doc,cs)
      observed={r["data"].get("metric_key") for r in self.records if r["kind"]=="report_metrics" and r["sources"][0]["document_id"]==doc["id"]}
      for category,required in {"ga4":["ga4.total_users","ga4.new_users","ga4.views","ga4.average_session_duration"],"search_console":["gsc.clicks","gsc.impressions","gsc.ctr"]}.items():
       if category in types:
        for name in required:
         if name not in observed:self.warn("missing_value",name+" was not reliably reported in supported source fields. No value was invented.",doc)
      unused=[c for c in doc["content"] if c["id"] not in self.consumed]
      for c in unused:self.add("unclassified","unclassified",doc,"content|"+str(c.get("ordinal",c["id"])),{"content_type":c["type"],"reason":"Unmapped source representation retained for future processing.","text":c.get("text",""),"source_content_id":c["id"]},[self.ref(doc,c)],{"text":c.get("text",""),"structured_data":c.get("data",{})})
      if unused:self.warn("unclassified_content",f"{len(unused)} source representations remain unclassified, including duplicate representations and context. Nothing was discarded.",doc)
    self.validate()
    counts={kind:sum(r["kind"]==kind for r in self.records) for kind in KINDS}
    return {"schema_version":1,"processor_version":VERSION,"classifications":self.classifications,"records":self.records,"warnings":self.warnings,"counts":counts,"sections":SECTIONS}
