<?php
// Only preserve list state for the shared clinical appointment screen.
function clinical_appointment_return($value): string {
    if(!is_string($value)) return '';
    $parts=parse_url($value);
    if(!$parts || ($parts['path']??'')!=='vet/appointments.php' || isset($parts['scheme']) || isset($parts['host'])) return '';
    parse_str($parts['query']??'', $params);
    $safe=[];
    foreach(['status','q','page','per_page','appointment_id'] as $key) if(isset($params[$key]) && is_scalar($params[$key])) $safe[$key]=(string)$params[$key];
    return 'vet/appointments.php'.($safe?'?'.http_build_query($safe):'').'#vetAppointmentView';
}
