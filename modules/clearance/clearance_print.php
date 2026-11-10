<?php
require_once __DIR__ . '/../../functions.php';
$u = require_login();
$letterId = (int)$_GET['letter_id'];

$st = $pdo->prepare("SELECT l.*, u.full_name AS sender_name FROM letters l JOIN users u ON u.id = l.sender_id WHERE l.id = ?");
$st->execute([$letterId]);
$l = $st->fetch();
if (!$l) die("Letter not found");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Print Letter</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <style>
        body { background: #555; padding: 20px; }
        .letter-paper { background: #fff; max-width: 800px; margin: 0 auto; padding: 50px; box-shadow: 0 0 10px rgba(0,0,0,.5); font-family: Georgia, serif; line-height: 1.8; }
        .letter-head { text-align: center; border-bottom: 2px solid #1B2A6B; padding-bottom: 20px; margin-bottom: 30px; }
        .letter-head h1 { color: #1B2A6B; font-size: 24px; margin: 0; }
        .letter-head h2 { color: #4DA6E8; font-size: 16px; margin: 5px 0; }
        .letter-body { white-space: pre-wrap; font-size: 16px; margin-bottom: 40px; }
        @media print { body { background: #fff; padding: 0; } .letter-paper { box-shadow: none; padding: 0; } }
    </style>
</head>
<body onload="window.print()">
    <div class="letter-paper">
        <div class="letter-head">
            <img src="<?= APP_URL ?>/assets/img/logo-misrak.png" style="width:80px;">
            <h1>MISRAK POLYTECHNIC COLLEGE</h1>
            <h2>ምስራቅ ፖሊቴክኒክ ኮሌጅ</h2>
            <p>P.O. Box 0000, Ethiopia | info@misrak.edu.et</p>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:20px; font-size:14px;">
            <div><strong>Ref:</strong> <?= e($l['ref_no']) ?></div>
            <div><strong>Date:</strong> <?= date('F j, Y') ?></div>
        </div>
        <h3 style="text-align:center; text-decoration:underline; margin-bottom:20px;"><?= e($l['subject']) ?></h3>
        <div class="letter-body"><?= e($l['body']) ?></div>
        
        <div style="margin-top:60px;">
            <p>Sincerely,</p>
            <br><br>
            <p><strong><?= e($l['sender_name']) ?></strong><br>Registrar's Office</p>
        </div>
    </div>
</body>
</html>