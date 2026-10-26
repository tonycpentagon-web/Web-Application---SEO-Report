export type ValidationStatus='AUTO_VALIDATED'|'HUMAN_VERIFIED'|'NEEDS_REVIEW'|'INVALID'|'NOT_APPLICABLE';
export type RecordValidation={status:ValidationStatus;issues:string[];checks:string[];evidence?:unknown[]};
export type ValidationOverview={id:string;current:boolean;fingerprint:string;evaluated_at:string;before:unknown;summary:{counts:Record<ValidationStatus,number>;documents:number;successfully_extracted:number;material_issues:number;data_ready:boolean;missing_metrics:string[];missing_sections:string[];document_issues:{code:string;document_id:string|null;document_name?:string;source_url?:string|null;message:string;source_period?:unknown}[];evidence_coverage:{supported:number;business_records:number}}};
export type NormalizationRun={id:string;status:string;created_at:string;started_at:string|null;completed_at:string|null;error_message:string|null;processor_version:string|null;summary:{counts:Record<string,number>;warning_count:number;classifications:{document_id:string;labels:{type:string;confidence:string}[];source_dates:{start:string;end:string}[]}[]}|null};
export type ReviewSummary={validation?:ValidationOverview|null;report_revision?:number;can_reevaluate?:boolean;run:NormalizationRun|null;runs:NormalizationRun[];sections:{section_key:string;label:string;record_count:number}[];counts:Record<string,number>;stale:boolean;can_normalize:boolean;can_review:boolean};
export type SourceReference={id:string;document_id:string;extraction_run_id:string;content_id:string|null;asset_id:string|null;location:Record<string,unknown>;quote:string;raw_value:unknown};
export type Eligibility={eligible:boolean;verified:boolean;verification_id:string|null;verified_by:number|null;verified_at:string|null;reasons:string[];warnings:{id:string;code:string;message:string;severity:string;blocking:boolean;resolved:boolean}[]};
export type BulkResult={report_id:string;report_title:string;period:string;selected_count:number;eligible_count:number;excluded_count:number;categories:Record<string,number>;results:{id:string;kind:string;status:string;review_status:string;revision:number;reasons:string[]}[]};
export type DataRecord={validation?:RecordValidation|null;id:string;kind:string;section_key:string;data:Record<string,unknown>;source_data:Record<string,unknown>;raw_value:unknown;confidence:string;review_status:string;revision:number;sources:SourceReference[];eligibility?:Eligibility;corrections?:{id:string;actor_id:number;reason:string;created_at:string;approval_method?:string;before_value:Record<string,unknown>;after_value:Record<string,unknown>}[]};
export type DataWarning={id:string;code:string;severity:string;message:string;document_id:string|null;source:string|Record<string,unknown>};
export type StructuredPreview={kpis:{metric_key:string;value:number|null;unit:string;conflict:boolean;observations:{record_id:string;value:number;review_status:string}[]}[];keyword_summary:Record<string,number>;seo_activity_counts:Record<string,number>;event_count_sum:number;ai_evidence_count:number;approved_only:boolean;stale:boolean;granularity:string;review_notice:string;traffic_channels:{id:string;data:Record<string,unknown>;review_status:string}[];device_metrics:{id:string;data:Record<string,unknown>;review_status:string}[];landing_page_metrics:{id:string;data:Record<string,unknown>;review_status:string}[];event_metrics:{id:string;data:Record<string,unknown>;review_status:string}[]};
export function display(value:unknown):string{return value===null||value===undefined?'Not reported':typeof value==='object'?Array.isArray(value)?value.map(display).join(' · '):JSON.stringify(value):String(value);}
export function recordName(r:Pick<DataRecord,'data'|'kind'>):string{return display(r.data.keyword??r.data.channel??r.data.device??r.data.event_name??r.data.query??r.data.label??r.data.url??r.data.content_type??r.kind);}
export function recordValue(r:Pick<DataRecord,'data'|'kind'>):string{
 const d=r.data;
 if(r.kind==='report_metrics')return display(d.value)+' '+display(d.unit)+' · '+String(d.definition).toUpperCase();
 if(r.kind==='keyword_rankings')return display(d.ranking_label)+' · '+display(d.category);
 if(r.kind==='traffic_channels')return display(d.total_users)+' users · '+display(d.new_users)+' new';
 if(r.kind==='device_metrics'||r.kind==='landing_page_metrics')return display(d.clicks)+' clicks · '+display(d.impressions)+' impressions'+(d.ctr!=null?' · '+display(d.ctr)+'% CTR':'');
 if(r.kind==='event_metrics')return display(d.count)+' reported events';
 if(r.kind==='seo_activities')return display(d.category)+' · Listed in source; live link unverified';
 if(r.kind==='ai_visibility_evidence')return display(d.platform)+' · '+display(d.brand_appearance);
 return display(d.reason);
}
