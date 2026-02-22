<?php
$data = json_decode($_POST['data'], true);
$labels = $data['labels'];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Tag Preview</title>

<style>
body {
    margin: 0;
    padding: 0;
}

/* Tag print area */
.print-area {
    width: 38mm;
    height: 38mm; /* ✅ Height increase karo owner name ke liye */
    position: relative;
    margin: 0;
    page-break-after: always;
}

.label-page {
    width: 50mm;
    padding: 10px;
    background: #fff;
    text-align: center;
    font-size: 18px;
    border-radius: 5px;
}

.cut-line {
    width: 100%;
    margin: 0px 0 0px 0;
    border-bottom: 2px dashed #666;
}

/* ✅ Owner name styling */
.owner-name {
    font-size: 16px;
    font-weight: bold;
    color: #2c3e50;
    margin-bottom: 5px;
    background: #ecf0f1;
    padding: 2px;
    border-radius: 3px;
}

/* ✅ Order ID styling */
.order-id {
    font-size: 14px;
    font-weight: bold;
    
    margin-bottom: 3px;
}

/* ✅ Customer name styling */
.customer-name {
    font-size: 16px;
    font-weight: bold;
    
    margin-bottom: 5px;
}

/* ✅ Comments styling */
.comments-section {
    margin-top: 3px;
    padding: 2px;
    background:; 
    border: ;
    border-radius: 3px;
    font-size: 20px !important;
    text-align: center;
}

.comment-item {
    display: inline-block;
    background: #e74c3c;
    color: black;
    padding: 1px 3px;
    margin: 1px;
    border-radius: 2px;
    font-size: 10px !important;
    font-weight: bold;
}

@media print {
    body {
        background: none;
    }
    .owner-name {
        background: #ecf0f1 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .comments-section {
        background: #fff3cd !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .comment-item {
        background:;
        color: white !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

</head>
<body>

<?php foreach ($labels as $lbl): ?>

<?php
// ⭐ Convert delivery date format (2025-11-30 → Sun 30 Nov 2025)
$rawDate = $lbl['delivery'];
$formattedDate = date("D d M Y", strtotime($rawDate));

// ✅ Comments processing
$comments = $lbl['comments'] ?? [];
$hasComments = !empty($comments) && is_array($comments);
?>

<div class="print-area">
    <div class="label-page">

        <!-- ✅ OWNER NAME (TOP) -->
        <?php if(isset($lbl['owner_name']) && !empty($lbl['owner_name'])): ?>
        <div class="owner-name"><?= $lbl['owner_name'] ?></div>
        <?php endif; ?>

        <!-- ✅ ORDER ID (OWNER KE BAD) -->
        <div class="order-id">   <?= $lbl['order'] ?></div>

        <!-- ✅ CUSTOMER NAME (ORDER ID KE BAD) -->
        <div class="customer-name"><?= $lbl['customer'] ?></div>

        <?= $formattedDate ?><br>
        <?= $lbl['product'] ?><br>
        <?= $lbl['service'] ?><br>
        <?= $lbl['payment'] ?><br>
        <b>T<?= $lbl['total'] ?></b><br>

        <!-- ✅ COMMENTS SECTION -->
        <?php if($hasComments): ?>
        <div class="comments-section">
            <?php foreach($comments as $comment): ?>
                <span class="comment-item"><?= htmlspecialchars($comment) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="cut-line"></div>
    </div>
</div>

<?php endforeach; ?>

<script>
// TSPL Printer Commands
let tspl = "";

<?php foreach ($labels as $lbl): ?>

<?php
$rawDate = $lbl['delivery'];
$formattedDate = date("D d M Y", strtotime($rawDate));

// ✅ Comments processing for TSPL
$comments = $lbl['comments'] ?? [];
$hasComments = !empty($comments) && is_array($comments);
$commentsText = $hasComments ? implode(", ", $comments) : "";
?>

tspl += `

GAP 0 mm, 0 mm
DIRECTION 0,0
REFERENCE 0,0
OFFSET 0 mm
SET PEEL OFF
SET CUTTER OFF
SET PARTIAL_CUTTER OFF
SET TEAR ON
CLS

<?php if(isset($lbl['owner_name']) && !empty($lbl['owner_name'])): ?>
// ✅ OWNER NAME IN TSPL (TOP)
TEXT 10,10,"8",0,1,1,"<?= $lbl['owner_name'] ?>"
// ✅ ORDER ID (OWNER KE BAD)
TEXT 10,25,"8",0,1,1,"Order: <?= $lbl['order'] ?>"
// ✅ CUSTOMER NAME (ORDER ID KE BAD)
TEXT 10,40,"8",0,1,1,"<?= $lbl['customer'] ?>"
TEXT 10,55,"8",0,1,1,"Delivery: <?= $formattedDate ?>"
TEXT 10,70,"8",0,1,1,"<?= $lbl['product'] ?>"
TEXT 10,85,"8",0,1,1,"<?= $lbl['service'] ?>"
TEXT 10,100,"8",0,1,1,"<?= $lbl['payment'] ?>"
<?php else: ?>
// ✅ AGAR OWNER NAME NAHI HAI TO DIRECT ORDER ID
TEXT 10,10,"8",0,1,1,"Order: <?= $lbl['order'] ?>"
TEXT 10,25,"8",0,1,1,"<?= $lbl['customer'] ?>"
TEXT 10,40,"8",0,1,1,"Delivery: <?= $formattedDate ?>"
TEXT 10,55,"8",0,1,1,"<?= $lbl['product'] ?>"
TEXT 10,70,"8",0,1,1,"<?= $lbl['service'] ?>"
TEXT 10,85,"8",0,1,1,"<?= $lbl['payment'] ?>"
<?php endif; ?>

<?php if($hasComments): ?>
// ✅ COMMENTS IN TSPL
TEXT 10,115,"10",0,1,1,"Comments:"
TEXT 10,130,"10",0,1,1,"<?= substr($commentsText, 0, 20) ?>"
<?php if(strlen($commentsText) > 20): ?>
TEXT 10,145,"6",0,1,1,"<?= substr($commentsText, 20, 20) ?>"
<?php endif; ?>
TEXT 10,165,"8",0,1,1,"T<?= $lbl['total'] ?>"
<?php else: ?>
TEXT 10,115,"8",0,1,1,"T<?= $lbl['total'] ?>"
<?php endif; ?>

PRINT 1,1
CLS
`;

<?php endforeach; ?>

// Print preview
window.print();

// Send to print server
fetch("http://localhost:3000/print", {
    method: "POST",
    headers: {"Content-Type": "text/plain"},
    body: tspl
}).catch(err => {
    console.log("Print server not available, preview only");
});
</script>

</body>
</html>