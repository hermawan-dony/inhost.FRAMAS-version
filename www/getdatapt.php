<?php 
include('init.php'); 
 
$hostprd = "https://apis-gw.beacukai.go.id/v2/Referensi/v1/Npwp16/get-data-perusahaan-npwp16?nitku=0011204369057000000000" ;
$hostprd = "https://apis-gw.beacukai.go.id/v2/amws/v1/user/user-info-by-email?email=maikel.wawoh%40framas.com" ;


 $bearer = $_SESSION["token"] ;  
 echo  $bearer ; exit ;
$bearer = "eyJhbGciOiJSUzI1NiIsInR5cCIgOiAiSldUIiwia2lkIiA6ICIzbXo1OTYwdE9MbkhhT0JLTHRQSG15N2VmT0plQVo5UmVZdkJMOGxVZDhFIn0.eyJleHAiOjE3MzYwNTI3OTEsImlhdCI6MTczNjA0NTU5MSwianRpIjoiMmM5MzdkMTktNDIyYi00MzAxLWE2MGUtOTY5ZDhjZmEwYmM2IiwiaXNzIjoiaHR0cHM6Ly9zc28tc3NvLXBvcnRhbC5hcHBzLnByb2RkYy5jdXN0b21zLmdvLmlkL2F1dGgvcmVhbG1zL3BvcnRhbF9oMmgiLCJzdWIiOiI5NDc1NWE0ZC0xNzQ4LTQ2MzUtODBmYi0wMjIwMDU5YThkMjUiLCJ0eXAiOiJCZWFyZXIiLCJhenAiOiJwb3J0YWxfbWZhX2NsaWVudCIsInNlc3Npb25fc3RhdGUiOiJkNzY3MTkxYS04Njg3LTQ3MDEtOTZiZS1mZjc0Mzc3YmEyZjAiLCJhY3IiOiIxIiwic2NvcGUiOiJvcGVuaWQgZW1haWwgcHJvZmlsZSIsInNpZCI6ImQ3NjcxOTFhLTg2ODctNDcwMS05NmJlLWZmNzQzNzdiYTJmMCIsImVtYWlsX3ZlcmlmaWVkIjp0cnVlLCJyb2xlIjoiMDYwOWNjYzItMjYzZi00ZDY3LTgwY2QtNDhkZWY0MzBhNDQwLDIwZTA1Y2QyLTE2ZGItNGUxZC05YWZmLTFjYTYyODU0NmVlMSwzMGM3YjNlZC02YmU1LTRhN2MtOGRlZi1iOTdlMWYyYjc5MGYsMzFiZTFhYjktMGEzZS00NDJiLWIxNTctZWZmNDVlZGVmZDIyLDMxYmUxYWI5LTBhM2UtNDQyYi1iMTU3LWVmZjQ1ZWRlZmQyMiwzMWJlMWFiOS0wYTNlLTQ0MmItYjE1Ny1lZmY0NWVkZWZkMjIsOWZhODNhMjktYzI3YS00YjYxLWFiM2ItNWE4OWFhYWE5NzQwIiwiaWRlbnRpdGFzIjoiMDEwODE2MDY0NDMxMDAwIiwib3RwIjoiMiIsInByZWZlcnJlZF91c2VybmFtZSI6Im1haWtlbHdhd29oIiwiaWRfdXNlciI6IjJmNjE0NjI2LTc5OTUtNGY3OS1hMTEzLTIyODI1OWQ2ODNiYSIsImdpdmVuX25hbWUiOiJNQUlLRUwgV0FXT0giLCJuYW1lIjoiTUFJS0VMIFdBV09IIiwibml0a3UiOiIwMDEwODE2MDY0NDMxMDAwMDAwMDAwIiwiZmFtaWx5X25hbWUiOiIiLCJlbWFpbCI6Im1haWtlbC53YXdvaEBmcmFtYXMuY29tIn0.g_I3Oi5-YgJNLcVNkv1VqWONCRmPf1jND0PxN8CwPEG1gytVhRtUkVQJbQiyEVTqapBe_kFxd1uG4yZEpImEzWzyWqOrKaxAEuK7qrjTNzl-s7v71A63kkeD6Ib5YnDa3Q7fjtkzwAjUholCpfEJdPpZ8tg__KnF_9db_3hK4HXmrQZOczyHga6Zw6H6QS-dkv5wJXC2Yw94DePbpeoYmN7ifVeC-rMgBKZ3SHzze2HbdwyvBpWWeE6pgzqwEQqnL9uwLhOUNGSxDm_DDG2vqqJR2dlh46YfXXGWriN_dJYzvKYs3HzbrcKa2Lb3p19DT0mXiiNjxvn1Eo3puIDqMQ" ;
$curl = curl_init(); 
curl_setopt_array($curl, [
  CURLOPT_URL => $hostprd,
  CURLOPT_SSL_VERIFYHOST => false,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "GET",
//  CURLOPT_POSTFIELDS => $sendrest,
  CURLOPT_HTTPHEADER => [
    "Authorization: Bearer $bearer",
    "Content-Type: application/json"
  ],
]);
$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
  echo "cURL Error #:" . $err;
} else {
    echo $response;
	 
}	
?>