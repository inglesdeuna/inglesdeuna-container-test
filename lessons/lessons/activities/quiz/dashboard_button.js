(function () {
  function addDashboardButtons() {
    if (document.getElementById('quiz-dashboard-buttons')) return;

    var top = document.querySelector('.top');
    if (!top) return;

    var wrap = document.createElement('div');
    wrap.id = 'quiz-dashboard-buttons';
    wrap.className = 'quiz-dashboard-buttons';

    var student = document.createElement('a');
    student.className = 'quiz-dashboard-btn student-dashboard-btn';
    student.href = '../../academic/student_dashboard.php';
    student.textContent = 'Student Dashboard';

    var teacher = document.createElement('a');
    teacher.className = 'quiz-dashboard-btn teacher-dashboard-btn';
    teacher.href = '../../academic/dashboard.php';
    teacher.textContent = 'Teacher Dashboard';

    wrap.appendChild(student);
    wrap.appendChild(teacher);
    top.appendChild(wrap);
  }

  var style = document.createElement('style');
  style.textContent = [
    '.top{gap:16px;align-items:center;flex-wrap:wrap}',
    '.quiz-dashboard-buttons{display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap;margin-left:auto}',
    '.quiz-dashboard-btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:1px solid #e9e3fb;border-radius:12px;padding:10px 14px;font-family:Nunito,Arial,sans-serif;font-size:13px;font-weight:900;box-shadow:0 7px 18px rgba(127,112,221,.13);transition:transform .15s ease,box-shadow .15s ease,filter .15s ease;white-space:nowrap}',
    '.quiz-dashboard-btn:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(127,112,221,.18);filter:brightness(1.03)}',
    '.student-dashboard-btn{background:#fff;color:#8070dd}',
    '.teacher-dashboard-btn{background:#8070dd;color:#fff;border-color:#8070dd}',
    '@media(max-width:760px){.quiz-dashboard-buttons{width:100%;justify-content:stretch}.quiz-dashboard-btn{flex:1;min-width:140px}.top{align-items:flex-start}}'
  ].join('\n');
  document.head.appendChild(style);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', addDashboardButtons);
  } else {
    addDashboardButtons();
  }
})();
