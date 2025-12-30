document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});

function startExamTimer(totalSeconds, sectionSeconds, elementId, sectionElementId) {
    let totalTimeLeft = totalSeconds;
    let sectionTimeLeft = sectionSeconds;
    
    const totalTimer = document.getElementById(elementId);
    const sectionTimer = document.getElementById(sectionElementId);
    
    const interval = setInterval(function() {
        totalTimeLeft--;
        sectionTimeLeft--;
        
        if (totalTimer) {
            const hours = Math.floor(totalTimeLeft / 3600);
            const minutes = Math.floor((totalTimeLeft % 3600) / 60);
            const seconds = totalTimeLeft % 60;
            totalTimer.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }
        
        if (sectionTimer) {
            const minutes = Math.floor(sectionTimeLeft / 60);
            const seconds = sectionTimeLeft % 60;
            sectionTimer.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }
        
        if (totalTimeLeft <= 0 || sectionTimeLeft <= 0) {
            clearInterval(interval);
            document.getElementById('autoSubmitForm')?.submit();
        }
    }, 1000);
}

function requestFullscreen() {
    const elem = document.documentElement;
    if (elem.requestFullscreen) {
        elem.requestFullscreen();
    } else if (elem.webkitRequestFullscreen) {
        elem.webkitRequestFullscreen();
    } else if (elem.msRequestFullscreen) {
        elem.msRequestFullscreen();
    }
}

function detectViolations() {
    let violations = 0;
    const maxViolations = 3;
    
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            violations++;
            updateViolationCounter(violations);
            logViolation('tab_switch');
            
            if (violations >= maxViolations) {
                alert('Maximum violations reached. Exam will be auto-submitted.');
                document.getElementById('autoSubmitForm')?.submit();
            }
        }
    });
    
    window.addEventListener('blur', function() {
        violations++;
        updateViolationCounter(violations);
        logViolation('window_blur');
        
        if (violations >= maxViolations) {
            document.getElementById('autoSubmitForm')?.submit();
        }
    });
}

function updateViolationCounter(count) {
    const counter = document.getElementById('violationCount');
    if (counter) {
        counter.textContent = count;
    }
}

function logViolation(type) {
    const sessionId = document.getElementById('sessionId')?.value;
    if (sessionId) {
        fetch('/public/student/log_violation.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `session_id=${sessionId}&type=${type}`
        });
    }
}

function setupAutoSave(formId, iframeId, interval = 35000) {
    const form = document.getElementById(formId);
    const iframe = document.getElementById(iframeId);
    
    if (form && iframe) {
        setInterval(function() {
            form.target = iframeId;
            form.submit();
            console.log('Auto-save triggered');
        }, interval);
    }
}

document.addEventListener('contextmenu', function(e) {
    if (document.body.classList.contains('exam-mode')) {
        e.preventDefault();
    }
});

document.addEventListener('copy', function(e) {
    if (document.body.classList.contains('exam-mode')) {
        e.preventDefault();
    }
});

document.addEventListener('cut', function(e) {
    if (document.body.classList.contains('exam-mode')) {
        e.preventDefault();
    }
});
