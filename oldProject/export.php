<?php
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

$test = '{"atoken":"eyJhbGciOiJSUzI1NiIsImtpZCI6IjFEMzM4NzBFNjQ5NTY5RDY3REU4Rjc5OEE1OEY1RDY3MEVCMjhGMjIiLCJ4NXQiOiJIVE9IRG1TVmFkWjk2UGVZcFk5ZFp3NnlqeUkiLCJ0eXAiOiJKV1QifQ.eyJodHRwOi8vc2NoZW1hcy54bWxzb2FwLm9yZy93cy8yMDA1LzA1L2lkZW50aXR5L2NsYWltcy9uYW1lIjoic3RlZmFuLnZhbmtyYW5lbkBrZWlsdW5kcHVya2wuZGUiLCJleHAiOiIxNzYzNTUzNTQ0IiwibmJmIjoiMTc2MzU1MTc0NCIsIlVzZXJJZCI6ImRlZjQyMWMxLTk2MzAtNDdkMC04ODlmLTA4ZGQ2ZGY3ZjM1YyIsIkNsaWVudEluc3RhbmNlSWQiOiJlOWE3NTc3YS1mYjA3LTRhNWMtODIwMi04Yjg4ZWFmMjg4YTMiLCJPcmdhbmlzYXRpb25JZCI6IjAwMDAwMDAwLTAwMDAtMDAwMC0wMDAwLTAwMDAwMDAwMDAwMCIsIkFsbG93VXNlckNyZWF0aW9uIjoiZmFsc2UiLCJUb2tlblRhcmdldCI6IkNvbW1vbiJ9.ij_lQdS_6KmN9BGYew6KEi3Ure70ZT5L3t5iJk8ccMCZ3lHfqSB_jNXLbtu_2xTRpZ0f9vv6-2KBTm6sb2u8d9HMN_E3pA2iF16COChvQOe6dE3FltdWyKDG5mS8ns0WUKTNRhrIYvAaW_rWjha0G4hMf_ohmu3rqWTVRqH-3d0oUndUqRIyxraR5UTF2_11CInmkarmaXynPEBIO4HGYM0kE0m4ZD2JYedvYdubewmH73zYCYXXtw_Oi-Hw9PU9NgRdAhpIu056qQ4XjVHYfo7yJWa7WtxknMRcqs66L4Y2LpOlMHHyDaMUrFl7lOzzlPq8l6aIU_jpthjVaiDlcA","export":"amagno","magnetid":"a158d804-73d7-411d-4f32-08ddb8b1d0f4","profile":"Aufmass","stampid":"53cf8d71-d407-4438-622c-08ddb8b1d0fb","system":"onprem","template":"debitoren.txt","vaultid":"77c48136-a957-4f35-9e3e-08dd6df8204c","secret":"1"}';
// $_POST = (array)json_decode($test);

include("Helper.php");
include("FibuExportController.php");
$controller = new FibuExportController();
$_POST["secret"] = "1";
$controller->fibu_export();
?>
