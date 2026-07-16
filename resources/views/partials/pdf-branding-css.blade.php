{{-- Shared CSS for the company letterhead + footer, included inside a <style>
     block by every PDF template (hr.report, hr.schedule, ...). --}}
.pdf-letterhead {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
    padding-bottom: 8px;
    border-bottom: 2px solid #D4AF37;
}
.pdf-letterhead-logo {
    height: 40px;
    width: auto;
    max-width: 90px;
    object-fit: contain;
}
.pdf-letterhead-info { line-height: 1.4; }
.pdf-letterhead-name { font-size: 14px; font-weight: bold; }
.pdf-letterhead-contact { font-size: 9px; color: #6b7280; }

.pdf-footer {
    position: fixed;
    bottom: -30px;
    left: 0;
    right: 0;
    height: 22px;
    font-size: 8px;
    color: #9ca3af;
    text-align: center;
    border-top: 1px solid #e5e7eb;
    padding-top: 4px;
}
.pdf-footer-co { font-weight: bold; }
.pdf-footer-contact { margin-inline-start: 6px; }
