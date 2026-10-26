@php
$categoryTitles=['article_submission'=>'Article Submission','profile_creation'=>'Profile Creation','guest_post'=>'Guest Post','social_bookmarking'=>'Social Bookmarking','ppt_submission'=>'PPT Submission','image_submission'=>'Image Submission'];
$activityGroups=collect($rows)->where('kind','seo_activities')->groupBy(fn($r)=>(string)(($r['data']['category']??'')?:'uncategorized'))->sortBy(fn($items,$key)=>array_search($key,array_keys($categoryTitles))===false?100:array_search($key,array_keys($categoryTitles)));
$activityCards=[['label'=>'Total submissions','value'=>$activityGroups->flatten(1)->count(),'unit'=>'count']];
foreach($activityGroups as $category=>$items)$activityCards[]=['label'=>$categoryTitles[$category]??ucwords(str_replace('_',' ',$category)),'value'=>$items->count(),'unit'=>'count'];
@endphp
@include('reports.partials.kpis',['cards'=>$activityCards])
@foreach($activityGroups as $category=>$items)
<h3>{{ $categoryTitles[$category]??ucwords(str_replace('_',' ',$category)) }} <small>{{ $items->filter(fn($r)=>filter_var($r['data']['url']??'',FILTER_VALIDATE_URL)&&in_array(strtolower(parse_url($r['data']['url'],PHP_URL_SCHEME)),['http','https']))->count() }} URLs</small></h3>
<ol class="activity-url-list">@foreach($items as $activity)<li class="url">{{ $activity['data']['url']??'URL not supplied' }}@if($activity['provisional']??false)<span class="row-state">Provisional source observation</span>@endif</li>@endforeach</ol>
@endforeach
<p class="section-note">Counts reflect listed source observations, including repeated URLs. Submitted URLs are not independently verified live backlinks.</p>
