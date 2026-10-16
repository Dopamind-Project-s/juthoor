<div class="table-responsive"><table class="table"><thead><tr><th>المعلومة</th><th>قبل</th><th>بعد</th></tr></thead><tbody>
@foreach(array_unique(array_merge(array_keys($before),array_keys($after))) as $key)
@php
$booleanFields=['is_living','is_active','is_primary'];
$integerFields=['birth_year','death_year','person_id','related_person_id','root_person_id','parent_branch_id','sort_order'];
$left=$before[$key] ?? null;$right=$after[$key] ?? null;
if(in_array($key,$booleanFields)){ $left=$left===null?null:(bool)$left;$right=$right===null?null:(bool)$right; }
if(in_array($key,$integerFields)){ $left=$left===null?null:(int)$left;$right=$right===null?null:(int)$right; }
@endphp
@if(!in_array($key,['id','family_tree_id','name_search']) && $left!==$right)
<tr><th>{{ __('genealogy.fields.'.$key) }}</th>
@foreach([$before,$after] as $values)
@php
$value=$values[$key] ?? null;
if(in_array($key,$booleanFields) && $value!==null){$value=(bool)$value;}
if(is_bool($value)){$display=$value?'نعم':'لا';}
elseif($key==='attribute_ids' && is_array($value)){$display=collect($value)->map(fn($id)=>$referenceLabels['attribute_ids'][$id] ?? '#'.$id)->implode('، ') ?: '—';}
elseif(is_array($value)){$display=json_encode($value,JSON_UNESCAPED_UNICODE);}
elseif(isset(($referenceLabels[$key] ?? [])[$value ?? ''])){$display=$referenceLabels[$key][$value];}
elseif(in_array($key,['verification_status','status','gender','relationship_type','branch_type','membership_mode']) && is_string($value) && \Illuminate\Support\Facades\Lang::has('genealogy.values.'.$value)){$display=__('genealogy.values.'.$value);}
else{$display=$value ?? '—';}
@endphp
<td class="text-break">{{ $display }}</td>
@endforeach</tr>
@endif @endforeach</tbody></table></div>
