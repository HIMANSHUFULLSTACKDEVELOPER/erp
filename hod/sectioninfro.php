<?php
require_once '../config.php';
if (!isLoggedIn() || !hasRole('hod')) { redirect('login.php'); }

$user_id = $_SESSION['user_id'];

$sql = "SELECT t.*, d.department_name, d.department_id 
        FROM teachers t JOIN departments d ON d.hod_id = t.user_id WHERE t.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$hod = $stmt->get_result()->fetch_assoc();
if (!$hod) { die("HOD profile not found."); }
$dept_id = $hod['department_id'];

/* ── POST: Add New Section ── */
$add_success = false;
$add_error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_section_name'])) {
    $sec_name = trim($_POST['new_section_name']);
    $sec_cap  = (int)($_POST['new_section_capacity'] ?? 60);
    $sec_desc = trim($_POST['new_section_description'] ?? '');
    $sec_color= preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['new_section_color'] ?? '') ? $_POST['new_section_color'] : '#f97316';

    if ($sec_name === '')                   { $add_error = 'Section name is required.'; }
    elseif ($sec_cap < 10 || $sec_cap > 500){ $add_error = 'Capacity must be between 10 and 500.'; }
    else {
        $chk = $conn->prepare("SELECT section_id FROM sections WHERE section_name = ?");
        $chk->bind_param("s", $sec_name);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) { $add_error = 'A section with this name already exists.'; }
        else {
            $ins = $conn->prepare("INSERT INTO sections (section_name, max_students, description) VALUES (?, ?, ?)");
            $ins->bind_param("sis", $sec_name, $sec_cap, $sec_desc);
            $add_success = $ins->execute();
            if (!$add_success) $add_error = 'Failed to create section.';
        }
    }
}

/* ── Fetch sections ── */
$all_sections_sql = "SELECT sec.section_id, sec.section_name, sec.max_students, sec.description,
    sem.semester_id, sem.semester_name, sem.semester_number, ss.academic_year,
    COUNT(DISTINCT ss.student_id) as student_count
  FROM sections sec
  LEFT JOIN student_semesters ss ON ss.section_id = sec.section_id AND ss.is_active = 1
  LEFT JOIN students s ON ss.student_id = s.student_id AND s.department_id = ?
  LEFT JOIN semesters sem ON ss.semester_id = sem.semester_id
  GROUP BY sec.section_id, sem.semester_id, ss.academic_year
  ORDER BY CASE WHEN sem.semester_number IS NULL THEN 9999 ELSE sem.semester_number END ASC, sec.section_name ASC";
$stmt = $conn->prepare($all_sections_sql);
$stmt->bind_param("i", $dept_id);
$stmt->execute();
$sections = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── Pre-fetch details ── */
$section_data = [];
foreach ($sections as $sec) {
    $sid = $sec['section_id']; $sem_id = $sec['semester_id']; $ay = $sec['academic_year'];
    $subjects = []; $students = [];
    if ($sem_id) {
        $stmt2 = $conn->prepare("SELECT DISTINCT sub.subject_id, sub.subject_name, sub.subject_code, sub.credits,
            t.full_name as teacher_name, t.designation as teacher_designation
          FROM subjects sub
          LEFT JOIN subject_teachers st ON st.subject_id=sub.subject_id AND st.semester_id=? AND st.section_id=? AND st.academic_year=?
          LEFT JOIN teachers t ON st.teacher_id=t.teacher_id
          WHERE sub.department_id=? AND sub.semester_id=? ORDER BY sub.subject_name");
        $stmt2->bind_param("iiisi", $sem_id, $sid, $ay, $dept_id, $sem_id);
        $stmt2->execute();
        $subjects = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

        $stmt3 = $conn->prepare("SELECT s.student_id, s.full_name, s.admission_number, s.date_of_birth,
            s.address, s.admission_year, s.course_id,
            u.email, u.phone,
            srn.roll_number_display, c.course_name
          FROM students s
          JOIN student_semesters ss ON ss.student_id=s.student_id AND ss.semester_id=? AND ss.section_id=? AND ss.is_active=1
          JOIN users u ON s.user_id=u.user_id
          LEFT JOIN student_roll_numbers srn ON srn.student_id=s.student_id AND srn.semester_id=? AND srn.section_id=?
          LEFT JOIN courses c ON s.course_id=c.course_id
          WHERE s.department_id=? ORDER BY s.full_name");
        $stmt3->bind_param("iiiis", $sem_id, $sid, $sem_id, $sid, $dept_id);
        $stmt3->execute();
        $students = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $extras = [];
    foreach ($students as $stu) {
        $sid2 = $stu['student_id'];
        $stmt4 = $conn->prepare("SELECT p.full_name as parent_name, p.relation FROM parents p JOIN parent_student ps ON ps.parent_id=p.parent_id WHERE ps.student_id=?");
        $stmt4->bind_param("i",$sid2); $stmt4->execute();
        $parent = $stmt4->get_result()->fetch_assoc();

        $stmt5 = $conn->prepare("SELECT sub.subject_name, sub.subject_code, sub.credits, ss2.status, ss2.grade FROM student_subjects ss2 JOIN subjects sub ON ss2.subject_id=sub.subject_id WHERE ss2.student_id=? AND ss2.semester_id=? ORDER BY sub.subject_name");
        $stmt5->bind_param("ii",$sid2,$sem_id); $stmt5->execute();
        $enrolled = $stmt5->get_result()->fetch_all(MYSQLI_ASSOC);

        $stmt6 = $conn->prepare("SELECT sub.subject_name, sub.subject_code, COUNT(*) as total,
            SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN a.status='absent'  THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN a.status='late'    THEN 1 ELSE 0 END) as late
          FROM attendance a JOIN subjects sub ON a.subject_id=sub.subject_id
          WHERE a.student_id=? AND a.semester_id=? GROUP BY a.subject_id ORDER BY sub.subject_name");
        $stmt6->bind_param("ii",$sid2,$sem_id); $stmt6->execute();
        $attendance = $stmt6->get_result()->fetch_all(MYSQLI_ASSOC);

        $extras[$sid2] = ['parent'=>$parent,'enrolled_subjects'=>$enrolled,'attendance'=>$attendance];
    }
    $section_data[] = ['section'=>$sec,'subjects'=>$subjects,'students'=>$students,'extras'=>$extras];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Section Information – College ERP</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ═══ ROOT ═══ */
:root{
    --pri:#f97316;--pri-dk:#ea580c;--pri-lt:#fff7ed;
    --success:#22c55e;--info:#3b82f6;--danger:#ef4444;--warn:#eab308;
    --dark:#0c0a09;--mid:#44403c;--gray:#78716c;--gray-lt:#a8a29e;
    --bg:#f5f3f0;--white:#fff;
    --card:0 2px 20px rgba(0,0,0,.07);--card-ho:0 8px 36px rgba(249,115,22,.2);
    --ease:cubic-bezier(.4,0,.2,1);--dur:.32s;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Outfit',sans-serif;background:var(--bg);color:var(--dark);min-height:100vh;}

/* ═══ SIDEBAR ═══ */
.sidebar{width:248px;background:linear-gradient(180deg,#111817,#1c1a17);color:#fff;position:fixed;top:0;left:0;height:100vh;overflow-y:auto;z-index:100;}
.sb-head{padding:24px 18px;text-align:center;background:linear-gradient(135deg,var(--pri),var(--pri-dk));}
.sb-ava{width:62px;height:62px;border-radius:14px;background:rgba(255,255,255,.18);backdrop-filter:blur(8px);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:700;margin:0 auto 10px;border:3px solid rgba(255,255,255,.28);}
.sb-name{font-size:1.05rem;font-weight:700;}.sb-dept{font-size:.76rem;opacity:.82;}
.sb-menu{padding:14px 0;}
.sb-menu a{padding:11px 20px;color:rgba(255,255,255,.6);text-decoration:none;display:flex;align-items:center;gap:13px;transition:all var(--dur) var(--ease);position:relative;font-size:.88rem;}
.sb-menu a::before{content:'';position:absolute;left:0;top:0;height:100%;width:3px;background:var(--pri);transform:scaleY(0);transition:transform var(--dur) var(--ease);}
.sb-menu a:hover::before,.sb-menu a.active::before{transform:scaleY(1);}
.sb-menu a:hover,.sb-menu a.active{background:rgba(249,115,22,.1);color:#fff;}
.sb-menu a i{width:18px;text-align:center;font-size:.92rem;}

/* ═══ MAIN ═══ */
.main{margin-left:248px;padding:26px 28px;min-height:100vh;}
.topbar{background:#fff;border-radius:16px;box-shadow:var(--card);padding:20px 26px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;}
.topbar h1{font-size:1.7rem;font-weight:800;background:linear-gradient(135deg,var(--pri),var(--pri-dk));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.topbar p{color:var(--gray);font-size:.84rem;margin-top:2px;}
.topbar-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
.btn{border:none;padding:10px 20px;border-radius:12px;cursor:pointer;font-weight:600;font-size:.84rem;font-family:'Outfit',sans-serif;display:inline-flex;align-items:center;gap:7px;transition:all var(--dur) var(--ease);text-decoration:none;}
.btn-pri{background:linear-gradient(135deg,var(--pri),var(--pri-dk));color:#fff;}
.btn-pri:hover{transform:translateY(-2px);box-shadow:0 6px 22px rgba(249,115,22,.35);}
.btn-gray{background:linear-gradient(135deg,var(--gray),#57534e);color:#fff;}
.btn-gray:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.22);}

/* ═══ SECTION CARDS ═══ */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px;}
.sec-card{background:#fff;border-radius:18px;box-shadow:var(--card);overflow:hidden;cursor:pointer;transition:all var(--dur) var(--ease);border:2px solid transparent;opacity:0;transform:translateY(20px);animation:fadeUp var(--dur) var(--ease) forwards;}
@keyframes fadeUp{to{opacity:1;transform:translateY(0);}}
.sec-card:hover{border-color:var(--pri);box-shadow:var(--card-ho);transform:translateY(-6px);}
.sec-card .stripe{height:5px;background:linear-gradient(90deg,var(--pri),var(--pri-dk),#fb923c);}
.sec-card .card-head{padding:18px 20px 0;display:flex;justify-content:space-between;align-items:flex-start;}
.sec-card .card-head h3{font-size:1.1rem;font-weight:700;color:var(--dark);}
.sec-card .card-head h3 i{color:var(--pri);margin-right:7px;}
.sem-tag{background:var(--pri-lt);color:var(--pri);font-size:.72rem;font-weight:700;padding:4px 11px;border-radius:8px;white-space:nowrap;}
.sem-tag.empty{background:#f3f4f6;color:var(--gray);}
.sec-card .card-body{padding:16px 20px 20px;}
.stats-row{display:flex;gap:10px;margin-top:12px;}
.stat-box{flex:1;text-align:center;background:var(--bg);border-radius:10px;padding:10px 4px;transition:background var(--dur) var(--ease);}
.sec-card:hover .stat-box{background:var(--pri-lt);}
.stat-box .sn{font-size:1.3rem;font-weight:800;color:var(--pri);display:block;}
.stat-box .sl{font-size:.68rem;color:var(--gray);font-weight:600;text-transform:uppercase;letter-spacing:.4px;}
.click-tip{margin-top:14px;text-align:center;font-size:.74rem;color:var(--gray-lt);font-weight:500;transition:all var(--dur) var(--ease);}
.sec-card:hover .click-tip{color:var(--pri);}
.empty{text-align:center;padding:60px 20px;color:var(--gray);grid-column:1/-1;}
.empty i{font-size:3rem;opacity:.22;margin-bottom:14px;display:block;}
.empty h3{font-size:1.1rem;color:var(--dark);margin-bottom:5px;}

/* ═══ DETAIL PANEL ═══ */
.detail-wrap{display:none;}
.detail-wrap.show{display:block;animation:panelIn .38s var(--ease) forwards;}
@keyframes panelIn{from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:translateY(0);}}
.bc{display:flex;align-items:center;gap:7px;margin-bottom:18px;flex-wrap:wrap;}
.bc span{font-size:.78rem;color:var(--gray);font-weight:600;}
.bc .sep{color:var(--pri);}.bc .act{color:var(--pri);}
.bc .lnk{cursor:pointer;transition:color var(--dur) var(--ease);}
.bc .lnk:hover{color:var(--pri);}
.dh{background:#fff;border-radius:18px;box-shadow:var(--card);overflow:hidden;margin-bottom:22px;}
.dh-top{background:linear-gradient(135deg,var(--pri),var(--pri-dk));color:#fff;padding:22px 26px;}
.dh-top h2{font-size:1.4rem;font-weight:700;}.dh-top h2 i{margin-right:9px;}
.dh-stats{padding:18px 26px;display:flex;gap:20px;flex-wrap:wrap;}
.dh-s{display:flex;align-items:center;gap:10px;}
.dh-icon{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;}
.dh-icon.o{background:linear-gradient(135deg,var(--pri),var(--pri-dk));}
.dh-icon.b{background:linear-gradient(135deg,var(--info),#2563eb);}
.dh-icon.g{background:linear-gradient(135deg,var(--success),#16a34a);}
.dh-s .ds-n{font-size:1.1rem;font-weight:800;color:var(--dark);}
.dh-s .ds-l{font-size:.7rem;color:var(--gray);font-weight:600;text-transform:uppercase;letter-spacing:.4px;}
.sec-title{font-size:1rem;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:8px;color:var(--dark);}
.sec-title i{color:var(--pri);}
.subj-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin-bottom:26px;}
.subj-card{background:#fff;border-radius:13px;box-shadow:var(--card);padding:16px 18px;border-left:4px solid var(--pri);transition:all var(--dur) var(--ease);opacity:0;transform:translateX(-12px);animation:sIn .3s var(--ease) forwards;}
@keyframes sIn{to{opacity:1;transform:translateX(0);}}
.subj-card:hover{box-shadow:var(--card-ho);transform:translateX(3px);border-left-color:var(--pri-dk);}
.subj-card .sc-head{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:6px;}
.subj-card .sc-head h4{font-size:.88rem;font-weight:700;color:var(--dark);line-height:1.3;}
.cr-tag{background:linear-gradient(135deg,var(--success),#16a34a);color:#fff;font-size:.68rem;font-weight:700;padding:3px 9px;border-radius:7px;white-space:nowrap;flex-shrink:0;}
.sc-code{font-size:.74rem;color:var(--gray);font-weight:600;margin-bottom:8px;}
.sc-teacher{display:flex;align-items:center;gap:8px;margin-top:8px;}
.sc-teacher .ta{width:28px;height:28px;border-radius:7px;background:linear-gradient(135deg,var(--pri),var(--pri-dk));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.74rem;flex-shrink:0;}
.sc-teacher .tn{font-size:.76rem;font-weight:600;color:var(--dark);}.sc-teacher .td{font-size:.68rem;color:var(--gray);}
.no-t{font-size:.74rem;color:var(--danger);background:rgba(239,68,68,.08);padding:5px 10px;border-radius:7px;margin-top:7px;display:inline-block;font-weight:600;}
.stu-list{display:flex;flex-direction:column;gap:9px;margin-bottom:24px;}
.stu-row{background:#fff;border-radius:13px;box-shadow:var(--card);padding:14px 18px;display:flex;align-items:center;gap:14px;cursor:pointer;transition:all var(--dur) var(--ease);border:2px solid transparent;opacity:0;transform:translateY(8px);animation:stuIn .28s var(--ease) forwards;}
@keyframes stuIn{to{opacity:1;transform:translateY(0);}}
.stu-row:hover{border-color:var(--pri);box-shadow:var(--card-ho);transform:translateY(-2px);}
.stu-row .sa{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,var(--pri),var(--pri-dk));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;flex-shrink:0;transition:transform var(--dur) var(--ease);}
.stu-row:hover .sa{transform:scale(1.08) rotate(2deg);}
.stu-row .si{flex:1;min-width:0;}
.stu-row .si .sn{font-weight:700;font-size:.9rem;color:var(--dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.stu-row .si .sa2{font-size:.74rem;color:var(--gray);}
.stu-row .roll{background:var(--bg);border-radius:9px;padding:5px 12px;font-size:.74rem;font-weight:700;color:var(--pri);white-space:nowrap;}
.stu-row .arr{color:var(--gray-lt);font-size:.82rem;transition:all var(--dur) var(--ease);}
.stu-row:hover .arr{color:var(--pri);transform:translateX(3px);}
.no-data-sm{font-size:.8rem;color:var(--gray-lt);padding:10px 0;text-align:center;}

/* ═══════════════════════════════════════════════════════════════════
   ADD NEW SECTION MODAL
   ═══════════════════════════════════════════════════════════════════ */
.overlay{display:none;position:fixed;inset:0;z-index:2000;background:rgba(0,0,0,.52);backdrop-filter:blur(5px);align-items:center;justify-content:center;}
.overlay.open{display:flex;}
.modal{background:#fff;border-radius:22px;width:90%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,.28);overflow:hidden;animation:mIn .3s var(--ease) forwards;position:relative;}
@keyframes mIn{from{opacity:0;transform:translateY(-32px) scale(.97);}to{opacity:1;transform:translateY(0) scale(1);}}

/* animated header */
.modal-hdr{background:linear-gradient(135deg,var(--pri),var(--pri-dk));color:#fff;padding:28px 26px 22px;position:relative;overflow:hidden;}
.modal-hdr::before{content:'';position:absolute;top:-30px;right:-30px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.1);}
.modal-hdr::after{content:'';position:absolute;bottom:-22px;left:18px;width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,.07);}
.mh-inner{display:flex;align-items:center;gap:16px;position:relative;z-index:1;}
.mh-icon{width:54px;height:54px;border-radius:15px;background:rgba(255,255,255,.18);backdrop-filter:blur(8px);border:2px solid rgba(255,255,255,.28);display:flex;align-items:center;justify-content:center;font-size:1.55rem;animation:iconPop .5s var(--ease) .12s both;}
@keyframes iconPop{0%{transform:scale(0) rotate(-20deg);opacity:0;}60%{transform:scale(1.15) rotate(5deg);}100%{transform:scale(1) rotate(0);opacity:1;}}
.mh-text h2{font-size:1.3rem;font-weight:700;}.mh-text p{font-size:.76rem;opacity:.8;margin-top:2px;}
.m-close{position:absolute;top:16px;right:16px;z-index:2;background:rgba(255,255,255,.2);border:none;color:#fff;width:30px;height:30px;border-radius:8px;cursor:pointer;font-size:.88rem;transition:background var(--dur) var(--ease);display:flex;align-items:center;justify-content:center;}
.m-close:hover{background:rgba(255,255,255,.38);}

/* step progress pills */
.steps{display:flex;gap:6px;padding:16px 26px 0;justify-content:center;}
.step-pill{flex:1;max-width:140px;height:4px;background:#e7e5e4;border-radius:2px;transition:background .4s var(--ease);}
.step-pill.active{background:var(--pri);}

.modal-body{padding:22px 26px 14px;}
.form-alert{padding:12px 15px;border-radius:10px;font-size:.82rem;font-weight:600;margin-bottom:18px;display:flex;align-items:center;gap:9px;animation:alertSlide .28s var(--ease);}
@keyframes alertSlide{from{opacity:0;transform:translateY(-8px);}to{opacity:1;transform:translateY(0);}}
.form-alert.error{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;}
.form-alert.success{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;}

.form-group{margin-bottom:18px;}
.form-group label{display:block;font-size:.74rem;font-weight:700;color:var(--mid);margin-bottom:7px;text-transform:uppercase;letter-spacing:.6px;}
.form-group label .opt{font-weight:400;text-transform:none;letter-spacing:0;color:var(--gray-lt);}
.input-wrap{position:relative;}
.input-wrap .in-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--pri);font-size:.88rem;pointer-events:none;z-index:1;}
.input-wrap .in-icon.top{top:14px;transform:none;}
.input-wrap input{width:100%;padding:12px 15px 12px 40px;border-radius:12px;border:2px solid #e7e5e4;font-family:'Outfit',sans-serif;font-size:.88rem;color:var(--dark);background:#fefefe;transition:all var(--dur) var(--ease);outline:none;}
.input-wrap input:focus{border-color:var(--pri);box-shadow:0 0 0 3px rgba(249,115,22,.15);background:#fff;}
.input-wrap textarea{width:100%;padding:34px 15px 12px 40px;border-radius:12px;border:2px solid #e7e5e4;font-family:'Outfit',sans-serif;font-size:.88rem;color:var(--dark);background:#fefefe;transition:all var(--dur) var(--ease);outline:none;resize:vertical;min-height:72px;}
.input-wrap textarea:focus{border-color:var(--pri);box-shadow:0 0 0 3px rgba(249,115,22,.15);background:#fff;}
.form-group .hint{font-size:.71rem;color:var(--gray-lt);margin-top:5px;padding-left:2px;}

/* color chips */
.color-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:4px;}
.color-chip{width:32px;height:32px;border-radius:9px;border:2.5px solid transparent;cursor:pointer;transition:all var(--dur) var(--ease);position:relative;}
.color-chip:hover{transform:scale(1.13);}
.color-chip.sel{border-color:var(--dark);box-shadow:0 0 0 2px rgba(0,0,0,.15);}
.color-chip.sel::after{content:'\f00c';font-family:'Font Awesome 6 Free';font-weight:900;color:#fff;font-size:.63rem;position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-shadow:0 1px 3px rgba(0,0,0,.45);}

/* capacity slider */
.slider-wrap{margin-top:6px;}
.cap-display{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.cap-display span{font-size:.74rem;color:var(--gray);font-weight:600;}
.cap-display .cap-val{font-size:1.15rem;font-weight:800;color:var(--pri);}
input[type=range]{-webkit-appearance:none;width:100%;height:6px;border-radius:3px;outline:none;cursor:pointer;background:linear-gradient(to right,var(--pri) var(--range-pct,20%),#e7e5e4 var(--range-pct,20%));}
input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,var(--pri),var(--pri-dk));border:3px solid #fff;box-shadow:0 2px 8px rgba(249,115,22,.4);cursor:pointer;transition:transform var(--dur) var(--ease);}
input[type=range]::-webkit-slider-thumb:hover{transform:scale(1.2);}

.modal-footer{padding:4px 26px 22px;display:flex;justify-content:flex-end;gap:10px;}

/* confetti */
.confetti-wrap{position:absolute;inset:0;pointer-events:none;overflow:hidden;border-radius:22px;z-index:5;}
.confetti-piece{position:absolute;width:8px;height:14px;border-radius:2px;opacity:0;animation:confDrop .9s var(--ease) forwards;}
@keyframes confDrop{0%{transform:translateY(-20px) rotate(0deg);opacity:1;}100%{transform:translateY(340px) rotate(720deg);opacity:0;}}

/* ═══════════════════════════════════════════════════════════════════
   STUDENT PROFILE MODAL  (TABBED)
   ═══════════════════════════════════════════════════════════════════ */
.prof-overlay{display:none;position:fixed;inset:0;z-index:3000;background:rgba(0,0,0,.55);backdrop-filter:blur(6px);align-items:center;justify-content:center;}
.prof-overlay.open{display:flex;}
.prof-modal{background:#fff;border-radius:22px;width:90%;max-width:700px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 70px rgba(0,0,0,.3);animation:pmIn .34s var(--ease) forwards;}
@keyframes pmIn{from{opacity:0;transform:translateY(-40px) scale(.96);}to{opacity:1;transform:translateY(0) scale(1);}}

/* profile header */
.ph{background:linear-gradient(135deg,var(--pri),var(--pri-dk));color:#fff;padding:28px 26px 0;position:relative;overflow:hidden;}
.ph::before{content:'';position:absolute;top:-50px;right:-50px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.08);}
.ph::after{content:'';position:absolute;bottom:50px;left:-30px;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.06);}
.ph-top{display:flex;align-items:flex-start;gap:18px;position:relative;z-index:1;}
.ph-ava{width:78px;height:78px;border-radius:20px;background:rgba(255,255,255,.2);backdrop-filter:blur(10px);color:#fff;display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:700;flex-shrink:0;border:3px solid rgba(255,255,255,.32);}
.ph-mid{flex:1;min-width:0;}
.ph-name{font-size:1.25rem;font-weight:700;}
.ph-sub{font-size:.76rem;opacity:.82;margin-top:3px;}
.ph-pills{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap;}
.ph-pill{background:rgba(255,255,255,.15);backdrop-filter:blur(4px);border:1px solid rgba(255,255,255,.22);color:#fff;font-size:.68rem;font-weight:600;padding:4px 10px;border-radius:20px;display:flex;align-items:center;gap:4px;}
/* donut */
.ph-donut-wrap{flex-shrink:0;position:relative;z-index:1;}
.ph-donut{width:70px;height:70px;}
.ph-donut-val{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:.82rem;font-weight:800;color:#fff;}
.ph-close{position:absolute;top:16px;right:16px;z-index:2;background:rgba(255,255,255,.2);border:none;color:#fff;width:30px;height:30px;border-radius:8px;cursor:pointer;font-size:.88rem;transition:background var(--dur) var(--ease);display:flex;align-items:center;justify-content:center;}
.ph-close:hover{background:rgba(255,255,255,.38);}

/* tabs */
.tabs{display:flex;gap:0;position:relative;z-index:1;margin-top:20px;}
.tab{flex:1;padding:11px 8px;text-align:center;font-size:.76rem;font-weight:700;color:rgba(255,255,255,.55);cursor:pointer;transition:color .3s var(--ease);position:relative;border:none;background:none;font-family:'Outfit',sans-serif;}
.tab::after{content:'';position:absolute;bottom:0;left:12%;width:76%;height:2.5px;background:#fff;border-radius:2px;transform:scaleX(0);transition:transform .3s var(--ease);}
.tab.active{color:#fff;}.tab.active::after{transform:scaleX(1);}
.tab i{margin-right:5px;}

/* tab panes */
.pb{padding:24px 26px;}
.tab-pane{display:none;animation:tabIn .26s var(--ease);}
.tab-pane.active{display:block;}
@keyframes tabIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}

/* ── Info tab ── */
.ig{display:grid;grid-template-columns:1fr 1fr;gap:11px;margin-bottom:18px;}
.ic{background:var(--bg);border-radius:12px;padding:14px 16px;transition:all var(--dur) var(--ease);}
.ic:hover{background:var(--pri-lt);transform:translateY(-2px);box-shadow:0 4px 14px rgba(249,115,22,.12);}
.ic .ic-l{font-size:.67rem;color:var(--gray);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;display:flex;align-items:center;gap:5px;}
.ic .ic-l i{color:var(--pri);}
.ic .ic-v{font-size:.86rem;font-weight:600;color:var(--dark);word-break:break-word;}
.ic.full{grid-column:1/-1;}
.parent-card{background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:12px;padding:15px 17px;margin-top:6px;display:flex;align-items:center;gap:14px;transition:transform var(--dur) var(--ease);}
.parent-card:hover{transform:translateY(-2px);}
.parent-card .pc-icon{width:38px;height:38px;background:rgba(180,120,10,.18);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#d97706;font-size:1.1rem;flex-shrink:0;}
.parent-card .pc-n{font-size:.88rem;font-weight:700;color:var(--dark);}
.parent-card .pc-r{font-size:.72rem;color:#92400e;font-weight:600;text-transform:capitalize;}
.no-parent{background:var(--bg);border-radius:11px;padding:14px;font-size:.78rem;color:var(--gray);text-align:center;margin-top:6px;}

/* ── Subjects tab ── */
.enr-grid{display:flex;flex-direction:column;gap:10px;}
.enr-item{background:var(--bg);border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:14px;transition:all var(--dur) var(--ease);border-left:4px solid var(--pri);}
.enr-item:hover{background:var(--pri-lt);transform:translateX(4px);box-shadow:0 3px 12px rgba(249,115,22,.12);}
.enr-item .ei-icon{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,var(--pri),var(--pri-dk));color:#fff;display:flex;align-items:center;justify-content:center;font-size:.88rem;flex-shrink:0;}
.enr-item .ei-info{flex:1;min-width:0;}
.enr-item .ei-name{font-size:.86rem;font-weight:700;color:var(--dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.enr-item .ei-code{font-size:.72rem;color:var(--gray);}
.enr-item .ei-right{display:flex;gap:7px;align-items:center;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end;}
.enr-badge{font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:7px;}
.enr-badge.active{background:#dcfce7;color:#16a34a;}
.enr-badge.completed{background:#dbeafe;color:#2563eb;}
.enr-badge.dropped{background:#fee2e2;color:#dc2626;}
.enr-badge.cr{background:var(--pri-lt);color:var(--pri);}
.enr-badge.grade{background:#ede9fe;color:#7c3aed;}

/* ── Attendance tab ── */
.att-summary-box{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;}
.att-s-item{flex:1;min-width:80px;background:var(--bg);border-radius:11px;padding:13px 8px;text-align:center;transition:all var(--dur) var(--ease);}
.att-s-item:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.08);}
.att-s-item .as-n{font-size:1.25rem;font-weight:800;}
.att-s-item .as-l{font-size:.65rem;color:var(--gray);font-weight:600;text-transform:uppercase;letter-spacing:.3px;margin-top:2px;}
.att-s-item.p .as-n{color:var(--success);}
.att-s-item.a .as-n{color:var(--danger);}
.att-s-item.l .as-n{color:var(--warn);}
.att-s-item.t .as-n{color:var(--info);}
.att-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--bg);}
.att-row:last-child{border-bottom:none;}
.att-row .att-name{font-size:.78rem;font-weight:600;color:var(--dark);width:150px;flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.att-bar-wrap{flex:1;background:var(--bg);border-radius:7px;height:20px;overflow:hidden;}
.att-bar{height:100%;border-radius:7px;display:flex;align-items:center;justify-content:center;transition:width .7s var(--ease);}
.att-bar.good{background:linear-gradient(90deg,var(--success),#16a34a);}
.att-bar.ok{background:linear-gradient(90deg,var(--warn),#ca8a04);}
.att-bar.bad{background:linear-gradient(90deg,var(--danger),#dc2626);}
.att-bar span{font-size:.63rem;color:#fff;font-weight:700;}
.att-pct{font-size:.72rem;font-weight:700;color:var(--gray);width:44px;text-align:right;flex-shrink:0;}

/* ═══ MEDIA ═══ */
@media(max-width:1024px){.main{padding:20px 18px;}.grid{grid-template-columns:repeat(auto-fill,minmax(230px,1fr));}}
@media(max-width:768px){
    .sidebar{width:210px;}.main{margin-left:210px;padding:18px 14px;}
    .topbar{flex-direction:column;align-items:flex-start;gap:12px;}.topbar h1{font-size:1.4rem;}
    .grid{grid-template-columns:1fr;}.subj-grid{grid-template-columns:1fr;}
    .ig{grid-template-columns:1fr;}
    .prof-modal{width:95%;max-height:92vh;}
    .ph-top{flex-direction:column;align-items:center;text-align:center;}
    .ph-donut-wrap{position:absolute;top:16px;right:16px;}
}
@media(max-width:600px){
    .sidebar{width:0;overflow:hidden;}.main{margin-left:0;padding:14px 10px;}
    .stu-row{padding:11px 12px;gap:10px;}
    .ph{padding:22px 18px 0;}.pb{padding:18px 14px;}
    .modal-body{padding:18px 16px;}.modal-footer{padding:4px 16px 18px;}
    .att-row .att-name{width:90px;}
    .ph-donut-wrap{display:none;}
    .enr-item{flex-wrap:wrap;}
    .enr-item .ei-right{width:100%;margin-top:4px;}
}
</style>
</head>
<body>
<div style="display:flex;">

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sb-head">
        <div class="sb-ava"><?= strtoupper(substr($hod['full_name'],0,1)) ?></div>
        <div class="sb-name"><?= htmlspecialchars($hod['full_name']) ?></div>
        <div class="sb-dept"><?= htmlspecialchars($hod['department_name']) ?></div>
    </div>
    <nav class="sb-menu">
        <a href="index.php"><i class="fas fa-home"></i> Dashboard</a>
        <a href="manage_student_semesters.php"><i class="fas fa-user-graduate"></i> Students</a>
        <a href="attandancereview.php"><i class="fas fa-clipboard-check"></i> Attendance Review</a>
        <a href="consolidatereport.php"><i class="fas fa-file-alt"></i> Consolidated Report</a>
        <a href="sections.php"><i class="fas fa-layer-group"></i> Sections</a>
        <a href="hod_classes.php"><i class="fas fa-chalkboard"></i> Classes</a>
        <a href="section_info.php" class="active"><i class="fas fa-th-large"></i> Section Info</a>
        <a href="manage_class_teachers.php"><i class="fas fa-user-tie"></i> Class Teachers</a>
        <a href="manage_substitutes.php"><i class="fas fa-exchange-alt"></i> Substitutes</a>
        <a href="dept_subjects.php"><i class="fas fa-book"></i> Subjects</a>
        <a href="dept_subjects_teacher.php"><i class="fas fa-book-reader"></i> Subject Teachers</a>
        <a href="dept_attendance.php"><i class="fas fa-calendar-check"></i> Attendance</a>
        <a href="dept_reports.php"><i class="fas fa-chart-line"></i> Reports</a>
        <a href="hod_profile.php"><i class="fas fa-user"></i> Profile</a>
        <a href="hod_setting.php"><i class="fas fa-cog"></i> Settings</a>
    </nav>
</aside>

<!-- MAIN -->
<main class="main">
    <div class="topbar">
        <div>
            <h1><i class="fas fa-th-large"></i> Section Information</h1>
            <p>Browse sections · explore subjects &amp; teachers · view full student profiles</p>
        </div>
        <div class="topbar-actions">
            <button class="btn btn-pri" onclick="openAddModal()"><i class="fas fa-plus"></i> Add New Section</button>
            <a href="index.php" class="btn btn-gray"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>
    </div>

    <!-- CARDS -->
    <div id="cardsView">
        <div class="grid" id="cardsGrid">
        <?php if (empty($section_data)): ?>
            <div class="empty"><i class="fas fa-layer-group"></i><h3>No Sections Yet</h3><p>Click <strong>Add New Section</strong> to get started.</p></div>
        <?php else:
            foreach ($section_data as $i => $sd):
                $sec=$sd['section'];$nSub=count($sd['subjects']);$nStu=count($sd['students']);$semTag=$sec['semester_name']??'';
        ?>
            <div class="sec-card" style="animation-delay:<?=$i*0.065?>s" onclick="showDetail(<?=$i?>)">
                <div class="stripe"></div>
                <div class="card-head">
                    <h3><i class="fas fa-layer-group"></i> <?=htmlspecialchars($sec['section_name'])?></h3>
                    <span class="sem-tag <?=$semTag?'':'empty'?>"><?=$semTag?:'No semester'?></span>
                </div>
                <div class="card-body">
                    <div style="font-size:.78rem;color:var(--gray);margin-top:4px;">
                        <?=$sec['academic_year']?'AY: '.$sec['academic_year']:''?>
                        <?=($sec['description']??'')?'&nbsp;·&nbsp;'.htmlspecialchars($sec['description']):''?>
                    </div>
                    <div class="stats-row">
                        <div class="stat-box"><span class="sn"><?=$nStu?></span><span class="sl">Students</span></div>
                        <div class="stat-box"><span class="sn"><?=$nSub?></span><span class="sl">Subjects</span></div>
                        <div class="stat-box"><span class="sn"><?=$sec['max_students']?></span><span class="sl">Capacity</span></div>
                    </div>
                    <div class="click-tip"><i class="fas fa-mouse-pointer"></i> Click to explore</div>
                </div>
            </div>
        <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- DETAIL -->
    <div id="detailView" class="detail-wrap">
        <div class="bc">
            <span class="lnk" onclick="backToCards()"><i class="fas fa-home"></i> Sections</span>
            <span class="sep">›</span><span class="act" id="bcLabel">–</span>
        </div>
        <div class="dh">
            <div class="dh-top"><h2><i class="fas fa-layer-group"></i> <span id="dhTitle">–</span></h2></div>
            <div class="dh-stats">
                <div class="dh-s"><div class="dh-icon o"><i class="fas fa-user-graduate"></i></div><div><div class="ds-n" id="dhStu">0</div><div class="ds-l">Students</div></div></div>
                <div class="dh-s"><div class="dh-icon b"><i class="fas fa-book"></i></div><div><div class="ds-n" id="dhSub">0</div><div class="ds-l">Subjects</div></div></div>
                <div class="dh-s"><div class="dh-icon g"><i class="fas fa-users"></i></div><div><div class="ds-n" id="dhCap">0</div><div class="ds-l">Capacity</div></div></div>
            </div>
        </div>
        <div class="sec-title"><i class="fas fa-book"></i> Subjects &amp; Teachers</div>
        <div id="subjGrid" class="subj-grid"></div>
        <div class="sec-title"><i class="fas fa-user-graduate"></i> Students</div>
        <div id="stuList" class="stu-list"></div>
    </div>
</main>
</div>

<!-- ═══ ADD SECTION MODAL ═══ -->
<div id="addModal" class="overlay">
    <div class="modal" id="addModalBox">
        <div class="confetti-wrap" id="confettiWrap"></div>
        <div class="modal-hdr">
            <div class="mh-inner">
                <div class="mh-icon"><i class="fas fa-layer-group"></i></div>
                <div class="mh-text"><h2>Create New Section</h2><p>Fill in the details below to add a section</p></div>
            </div>
            <button class="m-close" onclick="closeAddModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="steps"><div class="step-pill active" id="sp1"></div><div class="step-pill" id="sp2"></div><div class="step-pill" id="sp3"></div></div>
        <div class="modal-body">
            <?php if($add_error): ?><div class="form-alert error"><i class="fas fa-exclamation-circle"></i> <?=htmlspecialchars($add_error)?></div><?php endif; ?>
            <?php if($add_success): ?><div class="form-alert success"><i class="fas fa-check-circle"></i> Section created successfully!</div><?php endif; ?>
            <form method="POST" id="addSectionForm">
                <!-- Name -->
                <div class="form-group">
                    <label>Section Name</label>
                    <div class="input-wrap">
                        <i class="fas fa-layer-group in-icon"></i>
                        <input type="text" name="new_section_name" id="secNameIn" placeholder="e.g. CSEA, IT-B, ME" oninput="stepHighlight()" required>
                    </div>
                    <div class="hint">A short, unique identifier for this section</div>
                </div>
                <!-- Color -->
                <div class="form-group">
                    <label>Section Color</label>
                    <div class="color-row" id="colorRow">
                        <div class="color-chip sel" data-color="#f97316" style="background:#f97316"></div>
                        <div class="color-chip" data-color="#3b82f6" style="background:#3b82f6"></div>
                        <div class="color-chip" data-color="#22c55e" style="background:#22c55e"></div>
                        <div class="color-chip" data-color="#8b5cf6" style="background:#8b5cf6"></div>
                        <div class="color-chip" data-color="#ec4899" style="background:#ec4899"></div>
                        <div class="color-chip" data-color="#14b8a6" style="background:#14b8a6"></div>
                        <div class="color-chip" data-color="#f59e0b" style="background:#f59e0b"></div>
                        <div class="color-chip" data-color="#ef4444" style="background:#ef4444"></div>
                    </div>
                    <input type="hidden" name="new_section_color" id="secColorIn" value="#f97316">
                </div>
                <!-- Capacity -->
                <div class="form-group">
                    <label>Max Capacity</label>
                    <div class="slider-wrap">
                        <div class="cap-display"><span>10</span><span class="cap-val" id="capVal">60</span><span>500</span></div>
                        <input type="range" name="new_section_capacity" id="capSlider" min="10" max="500" value="60" oninput="updateCap()">
                    </div>
                    <div class="hint">Drag to set the maximum number of students allowed</div>
                </div>
                <!-- Description -->
                <div class="form-group">
                    <label>Description <span class="opt">(optional)</span></label>
                    <div class="input-wrap">
                        <i class="fas fa-pen-fancy in-icon top"></i>
                        <textarea name="new_section_description" placeholder="A short description of this section…"></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-gray" onclick="closeAddModal()"><i class="fas fa-times"></i> Cancel</button>
            <button class="btn btn-pri" onclick="document.getElementById('addSectionForm').submit()"><i class="fas fa-save"></i> Create Section</button>
        </div>
    </div>
</div>

<!-- ═══ STUDENT PROFILE MODAL ═══ -->
<div id="profModal" class="prof-overlay">
    <div class="prof-modal">
        <div class="ph">
            <button class="ph-close" onclick="closeProfile()"><i class="fas fa-times"></i></button>
            <div class="ph-top">
                <div class="ph-ava" id="phAva">?</div>
                <div class="ph-mid">
                    <div class="ph-name" id="phName">–</div>
                    <div class="ph-sub" id="phSub">–</div>
                    <div class="ph-pills" id="phPills"></div>
                </div>
                <div class="ph-donut-wrap">
                    <svg class="ph-donut" viewBox="0 0 70 70">
                        <circle cx="35" cy="35" r="28" fill="none" stroke="rgba(255,255,255,.22)" stroke-width="7"/>
                        <circle id="donutArc" cx="35" cy="35" r="28" fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round" stroke-dasharray="175.93" stroke-dashoffset="175.93" transform="rotate(-90 35 35)" style="transition:stroke-dashoffset .7s var(--ease);"/>
                    </svg>
                    <div class="ph-donut-val" id="donutVal">0%</div>
                </div>
            </div>
            <div class="tabs">
                <button class="tab active" onclick="switchTab('info')"><i class="fas fa-user"></i> Info</button>
                <button class="tab" onclick="switchTab('subjects')"><i class="fas fa-book-open"></i> Subjects</button>
                <button class="tab" onclick="switchTab('attendance')"><i class="fas fa-calendar-check"></i> Attendance</button>
            </div>
        </div>
        <div class="pb">
            <div class="tab-pane active" id="pane-info">
                <div class="ig" id="phGrid"></div>
                <div id="phParent"></div>
            </div>
            <div class="tab-pane" id="pane-subjects">
                <div id="phEnrolled"></div>
            </div>
            <div class="tab-pane" id="pane-attendance">
                <div class="att-summary-box" id="attSummary"></div>
                <div id="phAttendance"></div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ JS ═══ -->
<script>
const DATA = <?= json_encode($section_data, JSON_UNESCAPED_UNICODE) ?>;

/* Detail */
function showDetail(idx){
    const sd=DATA[idx],sec=sd.section;
    const label=`${sec.section_name}${sec.semester_name?' – '+sec.semester_name:''}`;
    document.getElementById('bcLabel').textContent=label;
    document.getElementById('dhTitle').textContent=label;
    document.getElementById('dhStu').textContent=sd.students.length;
    document.getElementById('dhSub').textContent=sd.subjects.length;
    document.getElementById('dhCap').textContent=sec.max_students;

    const sg=document.getElementById('subjGrid');sg.innerHTML='';
    if(!sd.subjects.length) sg.innerHTML='<div class="no-data-sm"><i class="fas fa-book"></i> No subjects for this section yet.</div>';
    sd.subjects.forEach((sub,i)=>{
        const tH=sub.teacher_name
            ?`<div class="sc-teacher"><div class="ta">${sub.teacher_name[0].toUpperCase()}</div><div><div class="tn">${sub.teacher_name}</div><div class="td">${sub.teacher_designation||'Faculty'}</div></div></div>`
            :`<div class="no-t"><i class="fas fa-exclamation-triangle"></i> No teacher assigned</div>`;
        const el=document.createElement('div');el.className='subj-card';el.style.animationDelay=(i*.055)+'s';
        el.innerHTML=`<div class="sc-head"><h4>${sub.subject_name}</h4><span class="cr-tag"><i class="fas fa-star"></i>${sub.credits} Cr</span></div><div class="sc-code">${sub.subject_code}</div>${tH}`;
        sg.appendChild(el);
    });

    const sl=document.getElementById('stuList');sl.innerHTML='';
    if(!sd.students.length) sl.innerHTML='<div class="no-data-sm"><i class="fas fa-user-slash"></i> No students enrolled yet.</div>';
    sd.students.forEach((stu,i)=>{
        const el=document.createElement('div');el.className='stu-row';el.style.animationDelay=(i*.04)+'s';
        el.onclick=()=>openProfile(stu,sd.extras[stu.student_id]||{});
        el.innerHTML=`<div class="sa">${stu.full_name[0].toUpperCase()}</div>
            <div class="si"><div class="sn">${stu.full_name}</div><div class="sa2">Admission: ${stu.admission_number}</div></div>
            ${stu.roll_number_display?`<div class="roll"><i class="fas fa-hashtag"></i>${stu.roll_number_display}</div>`:''}
            <div class="arr"><i class="fas fa-chevron-right"></i></div>`;
        sl.appendChild(el);
    });
    document.getElementById('cardsView').style.display='none';
    const dv=document.getElementById('detailView');dv.classList.add('show');dv.style.animation='none';void dv.offsetWidth;dv.style.animation='';
}
function backToCards(){
    document.getElementById('detailView').classList.remove('show');
    document.getElementById('cardsView').style.display='';
    document.querySelectorAll('.sec-card').forEach((c,i)=>{c.style.animation='none';void c.offsetWidth;c.style.animation=`fadeUp var(--dur) var(--ease) ${i*.065}s forwards`;});
}

/* Profile */
function openProfile(stu,ext){
    document.getElementById('phAva').textContent=stu.full_name[0].toUpperCase();
    document.getElementById('phName').textContent=stu.full_name;
    document.getElementById('phSub').textContent=`${stu.admission_number} · ${stu.course_name||'BTech'} · ${stu.admission_year}`;

    // pills
    let pills='';
    if(stu.roll_number_display) pills+=`<span class="ph-pill"><i class="fas fa-hashtag"></i>${stu.roll_number_display}</span>`;
    if(stu.course_name) pills+=`<span class="ph-pill"><i class="fas fa-graduation-cap"></i>${stu.course_name}</span>`;
    if(ext.enrolled_subjects&&ext.enrolled_subjects.length) pills+=`<span class="ph-pill"><i class="fas fa-book"></i>${ext.enrolled_subjects.length} Subjects</span>`;
    document.getElementById('phPills').innerHTML=pills;

    // donut
    const att=ext.attendance||[];
    let totP=0,totT=0,totA=0,totL=0;
    att.forEach(a=>{totP+=+a.present;totT+=+a.total;totA+=+a.absent;totL+=+a.late;});
    const pct=totT?Math.round(totP/totT*100):0;
    document.getElementById('donutArc').style.strokeDashoffset=175.93*(1-pct/100);
    document.getElementById('donutVal').textContent=pct+'%';

    // TAB 1
    document.getElementById('phGrid').innerHTML=`
        <div class="ic"><div class="ic-l"><i class="fas fa-hashtag"></i>Roll Number</div><div class="ic-v">${stu.roll_number_display||'Not assigned'}</div></div>
        <div class="ic"><div class="ic-l"><i class="fas fa-calendar-alt"></i>Date of Birth</div><div class="ic-v">${stu.date_of_birth||'–'}</div></div>
        <div class="ic"><div class="ic-l"><i class="fas fa-envelope"></i>Email</div><div class="ic-v">${stu.email||'–'}</div></div>
        <div class="ic"><div class="ic-l"><i class="fas fa-phone"></i>Phone</div><div class="ic-v">${stu.phone||'–'}</div></div>
        <div class="ic full"><div class="ic-l"><i class="fas fa-map-marker-alt"></i>Address</div><div class="ic-v">${stu.address||'–'}</div></div>
        <div class="ic"><div class="ic-l"><i class="fas fa-graduation-cap"></i>Admission Year</div><div class="ic-v">${stu.admission_year||'–'}</div></div>
        <div class="ic"><div class="ic-l"><i class="fas fa-university"></i>Course</div><div class="ic-v">${stu.course_name||'–'}</div></div>`;

    const p=ext.parent;
    document.getElementById('phParent').innerHTML=p&&p.parent_name
        ?`<div class="parent-card"><div class="pc-icon"><i class="fas fa-users"></i></div><div><div class="pc-n">${p.parent_name}</div><div class="pc-r">Relation: ${p.relation}</div></div></div>`
        :`<div class="no-parent"><i class="fas fa-user-slash"></i> No parent information on file</div>`;

    // TAB 2
    const enr=ext.enrolled_subjects||[];
    let eH='';
    if(!enr.length) eH='<div class="no-data-sm"><i class="fas fa-book"></i> No subjects enrolled yet.</div>';
    else{
        eH='<div class="enr-grid">';
        enr.forEach(s=>{
            eH+=`<div class="enr-item">
                <div class="ei-icon"><i class="fas fa-book"></i></div>
                <div class="ei-info"><div class="ei-name">${s.subject_name}</div><div class="ei-code">${s.subject_code}</div></div>
                <div class="ei-right">
                    <span class="enr-badge cr"><i class="fas fa-star"></i>${s.credits} Cr</span>
                    <span class="enr-badge ${s.status}">${s.status}</span>
                    ${s.grade?`<span class="enr-badge grade">${s.grade}</span>`:''}
                </div></div>`;
        });
        eH+='</div>';
    }
    document.getElementById('phEnrolled').innerHTML=eH;

    // TAB 3
    document.getElementById('attSummary').innerHTML=`
        <div class="att-s-item p"><div class="as-n">${totP}</div><div class="as-l">Present</div></div>
        <div class="att-s-item a"><div class="as-n">${totA}</div><div class="as-l">Absent</div></div>
        <div class="att-s-item l"><div class="as-n">${totL}</div><div class="as-l">Late</div></div>
        <div class="att-s-item t"><div class="as-n">${totT}</div><div class="as-l">Total</div></div>`;
    let aH='';
    if(!att.length) aH='<div class="no-data-sm"><i class="fas fa-calendar-check"></i> No attendance records yet.</div>';
    else att.forEach(a=>{
        const pc=a.total>0?Math.round(a.present/a.total*100):0;
        const cl=pc>=75?'good':pc>=50?'ok':'bad';
        aH+=`<div class="att-row"><div class="att-name">${a.subject_name}</div>
            <div class="att-bar-wrap"><div class="att-bar ${cl}" style="width:${pc}%"><span>${pc}%</span></div></div>
            <div class="att-pct">${a.present}/${a.total}</div></div>`;
    });
    document.getElementById('phAttendance').innerHTML=aH;

    switchTab('info');
    document.getElementById('profModal').classList.add('open');
}
function closeProfile(){document.getElementById('profModal').classList.remove('open');}

/* Tab switch */
function switchTab(name){
    document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
    const map={info:0,subjects:1,attendance:2};
    document.querySelectorAll('.tab')[map[name]].classList.add('active');
    document.getElementById('pane-'+name).classList.add('active');
}

/* Add modal */
function openAddModal(){document.getElementById('addModal').classList.add('open');}
function closeAddModal(){document.getElementById('addModal').classList.remove('open');}

/* Color chips */
document.getElementById('colorRow').addEventListener('click',function(e){
    const c=e.target.closest('.color-chip');if(!c)return;
    this.querySelectorAll('.color-chip').forEach(x=>x.classList.remove('sel'));
    c.classList.add('sel');
    document.getElementById('secColorIn').value=c.dataset.color;
});

/* Capacity slider */
function updateCap(){
    const v=document.getElementById('capSlider').value;
    document.getElementById('capVal').textContent=v;
    document.getElementById('capSlider').style.setProperty('--range-pct',((v-10)/490*100)+'%');
}
updateCap();

/* Step pills */
function stepHighlight(){
    const n=document.getElementById('secNameIn').value.trim();
    document.getElementById('sp2').classList.toggle('active',n.length>0);
    document.getElementById('sp3').classList.toggle('active',n.length>0);
}

/* Confetti on success */
<?php if($add_success): ?>
window.addEventListener('load',function(){
    openAddModal();
    const wrap=document.getElementById('confettiWrap');
    const colors=['#f97316','#3b82f6','#22c55e','#ec4899','#8b5cf6','#f59e0b','#ef4444','#14b8a6'];
    for(let i=0;i<30;i++){
        const p=document.createElement('div');p.className='confetti-piece';
        p.style.cssText=`background:${colors[i%colors.length]};left:${Math.random()*100}%;animation-delay:${Math.random()*.45}s;animation-duration:${.7+Math.random()*.5}s;width:${6+Math.random()*6}px;height:${10+Math.random()*8}px;`;
        wrap.appendChild(p);
    }
});
<?php elseif($add_error): ?>
window.addEventListener('load',function(){openAddModal();});
<?php endif; ?>

/* Close handlers */
document.getElementById('profModal').addEventListener('click',function(e){if(e.target===this)closeProfile();});
document.getElementById('addModal').addEventListener('click',function(e){if(e.target===this)closeAddModal();});
document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeProfile();closeAddModal();}});
</script>
</body>
</html>