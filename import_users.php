<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
?>

<?php
require 'db.php';

/* =========================
   USER BATCHES
========================= */

$batch1 = [
    ["Abel","Matoke","amatoke@ira.go.ke"],
    ["Ahmed","Mohamed","amohamed@ira.go.ke"],
    ["Ambrose","Terer","aterer@ira.go.ke"],
    ["Anne","Chelagat","achelagat@ira.go.ke"],
    ["Bernard","Gitangi","bgitangi@ira.go.ke"],
    ["Board","Trustees","bot@ira.go.ke"],
    ["Bosco","Mwanza","bmwanza@ira.go.ke"],
    ["Caroline","Chelimo","cchelimo@ira.go.ke"],
    ["Caroline","Mugwika","cmugwika@ira.go.ke"],
    ["Case","Legal","caselegal@ira.go.ke"]
];

$batch2 = [
    ["Case","System","casesystem@ira.go.ke"],
    ["Catherine","Nyawira","cnyawira@ira.go.ke"],
    ["Catherine","Nyamai","cnyamai@ira.go.ke"],
    ["Christopher","Wairoma","cwairoma@ira.go.ke"],
    ["Commissioner","Of Insurance","commins@ira.go.ke"],
    ["Corporate","Communications","communications@ira.go.ke"],
    ["Corporation","Secretary & DLS","csdls@ira.go.ke"],
    ["Daniel","Cherono","dcherono@ira.go.ke"],
    ["David","Bett","dbett@ira.go.ke"],
    ["David","Keiru","dkeiru@ira.go.ke"]
];

$batch3 = [
    ["Diana","Sawe","dsawe@ira.go.ke"],
    ["Dylan","Langat","dlangat@ira.go.ke"],
    ["Edmund","Waweru","ewaweru@ira.go.ke"],
    ["Edward","Kimani","ekimani@ira.go.ke"],
    ["Elizabeth","Musyoka","emusyoka@ira.go.ke"],
    ["Emily","Akinyi","eakinyi@ira.go.ke"],
    ["Emily","Onyango","eonyango@ira.go.ke"],
    ["Eric","Kisilu","ekisilu@ira.go.ke"],
    ["ERP","Admin","erpadmin@ira.go.ke"],
    ["ERS","Admin","ersadmin@ira.go.ke"]
];

$batch4 = [
    ["ERS-Test","Admin","ERStest-Admin@ira.go.ke"],
    ["Esther","Musyoki","emusyoki@ira.go.ke"],
    ["IRA","Ethics","ethics@ira.go.ke"],
    ["Eunice","Konchellah","ekonchellah@ira.go.ke"],
    ["Evans","Kibagendi","ekibagendi@ira.go.ke"],
    ["Faith","Musyoki","fmusyoki@ira.go.ke"],
    ["Felix","Chelimo","fchelimo@ira.go.ke"],
    ["Forti","SIEM","fortisiem@ira.go.ke"],
    ["Galgallo","Quche","gquche@ira.go.ke"],
    ["Gerald","Kago","gkago@ira.go.ke"]
];

$batch5 = [
    ["Godfrey","Kiptum","gkiptum@ira.go.ke"],
    ["Grace","Were","gwere@ira.go.ke"],
    ["ICT","Helpdesk","icthelpdesk@ira.go.ke"],
    ["Immaculate","Shamalla","ishamala@ira.go.ke"],
    ["Insurance","Fraud","insurancefraud@ira.go.ke"],
    ["IRA","Careers","careers@ira.go.ke"],
    ["IRA","Complaints","complaints@ira.go.ke"],
    ["IRA","DMS","iradms@ira.go.ke"],
    ["IRA","Legal Services","iralegalaffairs@ira.go.ke"],
    ["IRA","Researcher","researcher@ira.go.ke"]
];

$batch6 = [
    ["IRA","Scholarships","scholarships@ira.go.ke"],
    ["IRA","Tenders","tenders@ira.go.ke"],
    ["Isiann","Masha","imasha@ira.go.ke"],
    ["Jacqueline","Jerono","jjerono@ira.go.ke"],
    ["Jacqueline","Nanyama","jnanyama@ira.go.ke"],
    ["James","Kinyanjui","jkinyanjui@ira.go.ke"],
    ["James","Ndwiga","jndwiga@ira.go.ke"],
    ["Jemimah","Mwaniki","jmwaniki@ira.go.ke"],
    ["Joan","Kirika","jkirika@ira.go.ke"],
    ["John","Kipkorir","jkipkorir@ira.go.ke"]
];

$batch7 = [
    ["John","Njoroge","jnjoroge@ira.go.ke"],
    ["Joshua","Oselu","joselu@ira.go.ke"],
    ["Jude","Kibet","jkibet@ira.go.ke"],
    ["Juma","Mweni","jmweni@ira.go.ke"],
    ["Kalai","Musee","kmusee@ira.go.ke"],
    ["Leado","Stephen","lstephen@ira.go.ke"],
    ["Lydiah","Ndirangu","lndirangu@ira.go.ke"],
    ["Marcelus","Obote","mobote@ira.go.ke"],
    ["Martha","Mutsotso","mmutsoso@ira.go.ke"],
    ["Martha","Onsare","monsare@ira.go.ke"]
];

$batch8 = [
    ["Mary","Lwal","mlwal@ira.go.ke"],
    ["Mary","Nkoimu","mnkoimu@ira.go.ke"],
    ["Mary","Wambugu","mwambugu@ira.go.ke"],
    ["Mercy","Kiana","mkiana@ira.go.ke"],
    ["Monica","Thirima","mthirima@ira.go.ke"],
    ["Monicah","Ndungu","mndungu@ira.go.ke"],
    ["Moses","Chege","mchege@ira.go.ke"],
    ["Nancy","Otieno","notieno@ira.go.ke"],
    ["Naomi","Njoroge","nnjoroge@ira.go.ke"],
    ["Nelly","Chepkemoi","nchepkemoi@ira.go.ke"]
];

$batch9 = [
    ["Oldrine","Chepkemoi","ochepkemoi@ira.go.ke"],
    ["Pam","Sen","pamsen2023@ira.go.ke"],
    ["Pastor","Akhusama","pakhusama@ira.go.ke"],
    ["Paul","Maiyo","pmaiyo@ira.go.ke"],
    ["Peter","Ewoi","pewoi@ira.go.ke"],
    ["Procurement","","procurement@ira.go.ke"],
    ["Robert","Kuloba","rkuloba@ira.go.ke"],
    ["Rose","Mbori","rmbori@ira.go.ke"],
    ["Teresa","Mburu","tmburu@ira.go.ke"],
    ["Teresa","Nyatuka","tnyatuka@ira.go.ke"]
];

$batch10 = [
    ["Theresia","Mumo","tmumo@ira.go.ke"],
    ["Tinah","Odoyo","todoyo@ira.go.ke"],
    ["Titus","Osero","tosero@ira.go.ke"],
    ["Wellington","Njumwa","wnjumwa@ira.go.ke"],
    ["Wilfred","Kinayia","wkinayia@ira.go.ke"],
    ["Wilson","Ngugi","wngugi@ira.go.ke"],
    ["Wilson","Wachira","wwachira@ira.go.ke"],
    ["Jillo","Komba","jkomba@ira.go.ke"],
    ["Hellen","Jepkoech","hjepkoech@ira.go.ke"],
    ["Henry","Manji","hmanji@ira.go.ke"]
];

$batch11 = [
    ["Isaac","Busisa","ibusisa@ira.go.ke"],
    ["James","Mwaniki","jmwaniki@ira.go.ke"],
    ["Ken","Kiptoo","kkiptoo@ira.go.ke"],
    ["Martha","Muthoni","mmuthoni@ira.go.ke"],
    ["Mercy","Wambua","mwambua@ira.go.ke"],
    ["Moses","Ayiro","mayiro@ira.go.ke"],
    ["Ruth","Nthambi","rnthambi@ira.go.ke"],
    ["Vera","Kwamboka","vkwamboka@ira.go.ke"],
    ["Victor","Mwendwa","vmwendwa@ira.go.ke"],
    ["John","Mueke","jmueke@ira.go.ke"]
];

$batch12 = [
    ["Vanessa","Tiampati","vtiampati@ira.go.ke"],
    ["Sharleen","Kihima","skihima@ira.go.ke"],
    ["Natasha A.","Okuku","nokuku@ira.go.ke"],
    ["Joseph","Muturi","jmuturi@ira.go.ke"],
    ["Nina","Omwona","nomwona@ira.go.ke"],
    ["Sharon N.","Kimeu","skimeu@ira.go.ke"],
    ["Purity M.","Mwai","pmwai@ira.go.ke"],
    ["Janet K.","Jomo","jjomo@ira.go.ke"],
    ["Geofry O.","Onyango","gonyango@ira.go.ke"],
    ["Gentrix J.","Biwott","gbiwott@ira.go.ke"]
];

$batch13 = [
    ["Daisy","Chepkorir","dchepkorir@ira.go.ke"],
    ["Anthony T.","Muriithi","amuriithi@ira.go.ke"],
    ["John","Liban","jliban@ira.go.ke"],
    ["Hassan","Aress","No email"],
    ["Salome","Karanja","No email"],
    ["Musa","Mamuti","No email"],
    ["Manasseh","Mrima","No email"],
    ["Clive","Ongeri","No email"],
    ["Raphael","Bosire","No email"],
    ["Tabitha","Mwaniki","No email"]
];

$batch14 = [
    ["Simon","Odikor","No email"],
    ["Lucy","Kariuki","lkariuki@ira.go.ke"],
    ["Fosia M.","Abdula","fabdula@ira.go.ke"],
    ["Stephanie","Kibiego","skibiego@ira.go.ke"],
    ["Ken K.","Rotich","No email"],
    ["Peter","Ndirangu","pndirangu@ira.go.ke"],
    ["Mohamed Mohamed","Omar","mmohamed@ira.go.ke"],
    ["Patrick","Kiplang'at","pkiplangat@ira.go.ke"],
    ["Peter M.","Kinoti","pkinoti@ira.go.ke"],
    ["Ezra","Kaimenyi","ekaimenyi@ira.go.ke"]
];

$batch15 = [
    ["Essy","Jemutai","ejemutai@ira.go.ke"],
    ["Caroline J.","Rerimoi","crerimoi@ira.go.ke"],
    ["Joshua W.","Nyukuri","jnyukuri@ira.go.ke"],
    ["Joyce","Wavinya","jwavinya@ira.go.ke"],
    ["Bonface M.","Mong'are","bmongare@ira.go.ke"],
    ["Evelyn","Nyathira","enyathira@ira.go.ke"],
    ["Peter","Muchai","pmuchai@ira.go.ke"],
    ["Jackline","Rutere","jrutere@ira.go.ke"],
    ["Annette N.","Munyiri","amunyiri@ira.go.ke"],
    ["Beatrice M.","Naserian","bnaserian@ira.go.ke"],
    ["Collins","Kibet","ckibet@ira.go.ke"],
    ["Laureen A.","Opudo","lopudo@ira.go.ke"],
    ["Kivisu","Masinga","kmasinga@ira.go.ke"],
    ["Joyline","Mutai","jmutai@ira.go.ke"],
    ["Jastus O.","Kapis","kochieng@irake.onmicrosoft.com"],
    ["Nancy M.","Hiwot","nhiwot@ira.go.ke"],
    ["Elizabeth L.","Wekesa","ewekesa@ira.go.ke"],
    ["Lincoln M.","Mukui","lmukui@ira.go.ke"],
    ["Belinda K.","Kenneth","bkinya@ira.go.ke"],
    ["Phineas M.","Mutiria","pmuthuri@ira.go.ke"],
    ["Mickdad","Wabuko","mwabuko@ira.go.ke"],
    ["Mary W.","Ng'ang'a","mnganga@ira.go.ke"],
    ["Samwel L.","Kisotu","klemayian@ira.go.ke"],
    ["Fatuma I.","Barre","fbare@ira.go.ke"],
    ["Lisa M.","Mburu","lmburu@ira.go.ke"],
    ["Kelvin","Kinyua","kkinyua@ira.go.ke"],
    ["Joy Mella A.","Pherry","jpherry@ira.go.ke"],
    ["Natasha M.","Kaberia","nkaberia@ira.go.ke"],
    ["Fatuma M.","Abdi",""]
];

/* =========================
   MERGE ALL BATCHES
========================= */

$users = array_merge(
    $batch1,$batch2,$batch3,$batch4,$batch5,
    $batch6,$batch7,$batch8,$batch9,
    $batch10,$batch11,$batch12,$batch13,
    $batch14,$batch15
);

/* =========================
INSERT USERS
========================= */

$stmt = $conn->prepare("INSERT IGNORE INTO users
(full_name, email, password, role, status, new_role, super_password)
VALUES (?, ?, ?, 'user', 'active', 'user', NULL)");

foreach ($users as $user) {

    $first = trim($user[0]);
    $last  = trim($user[1]);
    $email = trim($user[2]);

    // Skip invalid emails
    if ($email == "No email" || empty($email)) continue;

    $full_name = $first . " " . $last;

    // Generate secure password: firstname@123
    $plain_password = $first . "@123";
    $hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);

    $stmt->bind_param("sss", $full_name, $email, $hashed_password);
    $stmt->execute();
}

echo "✅ Users imported successfully!";
?>