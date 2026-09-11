<?php
// Include beside the existing retrieval form. Scanning submits that form with its CSRF token.
$qrCameraVersion = (string) max(filemtime(__DIR__ . '/../assets/js/pet-qr-scanner.js'), filemtime(__DIR__ . '/../assets/css/pet-qr-scanner.css'));
$qrCameraLocalPath = ($_SESSION['role'] ?? 'staff') === 'admin' ? 'admin/qr.php' : 'staff/qr.php';
?>
<link rel="stylesheet" href="<?= e(app_url('assets/css/pet-qr-scanner.css')) ?>?v=<?= e($qrCameraVersion) ?>">
<section class="pet-qr-scanner" data-pet-qr-scanner data-form="#qrTokenForm" aria-label="Pet QR camera scanner">
    <div class="pet-qr-scanner-heading">
        <div><h3>Scan with your camera</h3><p>Hold the pet's QR code in front of your laptop webcam or connected camera.</p></div>
    </div>
    <div class="pet-qr-scanner-actions">
        <button class="pet-qr-camera-start" type="button" data-camera-start>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14.5 4 16 6h3a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h3l1.5-2Z"/><circle cx="12" cy="13" r="3.5"/></svg>
            Scan with camera
        </button>
        <button class="pet-qr-camera-stop" type="button" data-camera-stop hidden>Stop camera</button>
    </div>
    <label class="pet-qr-camera-choice" data-camera-choice hidden>Camera
        <select data-camera-select aria-label="Camera"></select>
    </label>
    <div class="pet-qr-camera-preview" data-camera-preview hidden>
        <video data-camera-video autoplay muted playsinline aria-label="Live camera preview"></video>
        <div class="pet-qr-camera-guide" aria-hidden="true"></div>
    </div>
    <p class="pet-qr-camera-status" data-camera-status role="status" aria-live="polite">The pet record opens automatically when a QR is detected.</p>
    <p class="pet-qr-camera-help" data-camera-help hidden>
        On the computer running Vetrix, <a href="<?= e('http://localhost' . app_url($qrCameraLocalPath)) ?>">open Vetrix on localhost</a> to use its camera.
        On other computers, use the clinic's HTTPS website address.
    </p>
    <noscript><p>Enable JavaScript to scan with a camera, or enter the QR token below.</p></noscript>
</section>
<script src="<?= e(app_url('assets/js/vendor/jsQR.js')) ?>" defer></script>
<script src="<?= e(app_url('assets/js/pet-qr-scanner.js')) ?>?v=<?= e($qrCameraVersion) ?>" defer></script>
