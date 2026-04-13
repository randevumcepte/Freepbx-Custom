#!/usr/bin/php
<?php
require('phpagi.php');
$agi = new AGI();
$agi->set_variable("hizmet_id",$argv[2]);
$randevuOlusturmaSonuc = randevuolustur($agi,$argv[2],$argv[3],date('Y-m-d',strtotime($argv[1])),date("H:i",strtotime($argv[1])),$argv[5],$argv[4],$argv[6],$argv[7],$argv[8]);
if($randevuOlusturmaSonuc['success']){
	
	$agi->set_variable('RANDEVU_SONUCU', 'Randevu talebiniz tarafımızda iletilmiş olup en kısa sürede sizinle iletişime geçilecektir. İyi günler dileriz');
	$agi->set_variable('AGI_SUCCESS',true);
}
else{

	$agi->set_variable('RANDEVU_SONUCU','Şu an sistemde bir sorun mevcut. Sizi operatöre aktarıyorum');
	$agi->set_variable('AGI_SUCCESS',false);
}		
function randevuolustur($agi,$hizmetid,$personelid,$tarih,$saat,$userId,$salonId,$sure,$fiyat,$odaid)
{
    $url = 'https://app.randevumcepte.com.tr/api/v1/santralRandevuEkle';
   
   //$agi->set_variable('hizmet_id',$hizmetid);
    
    $data = ['easistan'=>1,'olusturan_user_id'=>$user_id,'salon_id'=>$salonId,'user_id'=>$userId,'durum'=>0,'hizmetler' => [$hizmetid], 'randevuPersonelleri' => [$personelid],'tarih'=>$tarih,'saat'=>$saat,'hizmetSuresi'=>[$sure],'hizmetFiyati'=>[$fiyat],'randevuOdalari'=>[$odaid]];

    $agi->verbose("RANDEVU_JSON: " . json_encode($data, JSON_UNESCAPED_UNICODE));

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        error_log('cURL Hatası: ' . $error_msg);
        $agi->verbose('cURL Hatası: ' . $error_msg);
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    //JSON dönüşüm hatasını yakalama
    $decoded_response = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $json_error = json_last_error_msg();
        error_log('JSON Çözümleme Hatası: ' . $json_error);
        $agi->verbose('JSON Çözümleme Hatası: ' . $json_error);
        return null;
    }

    return $decoded_response;
}

?>
