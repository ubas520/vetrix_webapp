(() => {
    'use strict';
    document.querySelectorAll('[data-pet-qr-scanner]').forEach((scanner) => {
        if (scanner.dataset.initialized) return;
        scanner.dataset.initialized = 'true';
        const form = document.querySelector(scanner.dataset.form);
        const input = form?.querySelector('[name="token"]');
        if (!form || !input) return;
        const start = scanner.querySelector('[data-camera-start]');
        const stop = scanner.querySelector('[data-camera-stop]');
        const preview = scanner.querySelector('[data-camera-preview]');
        const video = scanner.querySelector('[data-camera-video]');
        const choice = scanner.querySelector('[data-camera-choice]');
        const select = scanner.querySelector('[data-camera-select]');
        const status = scanner.querySelector('[data-camera-status]');
        const help = scanner.querySelector('[data-camera-help]');
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d', { willReadFrequently: true });
        let stream = null;
        let timer = null;
        let generation = 0;
        let submitted = false;

        function message(text, state = '') {
            status.textContent = text;
            status.dataset.state = state;
        }

        function stopCamera() {
            generation++;
            clearTimeout(timer);
            timer = null;
            if (stream) stream.getTracks().forEach((track) => track.stop());
            stream = null;
            video.pause();
            video.srcObject = null;
            preview.hidden = true;
            stop.hidden = true;
            choice.hidden = true;
            start.disabled = false;
            select.disabled = false;
        }

        function readToken(value) {
            let token = value.trim();
            if (/^https?:\/\//i.test(token)) {
                try { token = new URL(token).searchParams.get('token') || ''; }
                catch { return ''; }
            }
            token = token.trim();
            return token && token.length <= 255 && !/[\x00-\x1f\x7f]/.test(token) ? token : '';
        }

        function scanFrame(session) {
            if (session !== generation || !stream || submitted) return;
            try {
                if (video.readyState >= 2 && video.videoWidth > 0) {
                    const scale = Math.min(1, 960 / video.videoWidth);
                    canvas.width = Math.floor(video.videoWidth * scale);
                    canvas.height = Math.floor(video.videoHeight * scale);
                    context.drawImage(video, 0, 0, canvas.width, canvas.height);
                    const pixels = context.getImageData(0, 0, canvas.width, canvas.height);
                    const code = window.jsQR(pixels.data, pixels.width, pixels.height, { inversionAttempts: 'attemptBoth' });
                    if (code?.data) {
                        const token = readToken(code.data);
                        if (token) {
                            submitted = true;
                            stopCamera();
                            input.value = token;
                            input.dispatchEvent(new Event('input', { bubbles: true }));
                            message('QR detected. Opening the pet record…', 'success');
                            // Always submit to this protected page; never navigate to a scanned external URL.
                            form.requestSubmit();
                            return;
                        }
                        message('This QR has no pet token. Hold the pet’s Vetrix QR in front of the camera.', 'error');
                    }
                }
                timer = setTimeout(() => scanFrame(session), 150);
            } catch {
                stopCamera();
                submitted = false;
                message('The camera could not read this image. Try scanning again or enter the token below.', 'error');
            }
        }

        async function startCamera(deviceId) {
            stopCamera();
            submitted = false;
            help.hidden = true;
            if (!window.isSecureContext) {
                message('Camera access needs HTTPS or localhost. You can still enter a QR token below.', 'error');
                help.hidden = false;
                return;
            }
            if (!navigator.mediaDevices?.getUserMedia) {
                message('This browser cannot access a camera. Use a current browser such as Edge or Chrome, or enter the token below.', 'error');
                return;
            }
            if (!context || typeof window.jsQR !== 'function') {
                message('The QR scanner did not load. Refresh this page and try again, or enter the token below.', 'error');
                return;
            }
            const session = generation;
            start.disabled = true;
            stop.hidden = false;
            select.disabled = true;
            message('Allow camera access when your browser asks.');
            try {
                const camera = await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
                });
                if (session !== generation) {
                    camera.getTracks().forEach((track) => track.stop());
                    return;
                }
                stream = camera;
                video.srcObject = camera;
                preview.hidden = false;
                await video.play();
                if (session !== generation) return;
                message('Camera ready. Hold the QR steady and keep the entire code visible.');
                camera.getVideoTracks()[0]?.addEventListener('ended', () => {
                    if (session !== generation) return;
                    stopCamera();
                    message('The camera disconnected. Reconnect it and try again.', 'error');
                }, { once: true });
                scanFrame(session);
                try {
                    const devices = (await navigator.mediaDevices.enumerateDevices()).filter((device) => device.kind === 'videoinput');
                    if (session !== generation) return;
                    const activeId = camera.getVideoTracks()[0]?.getSettings().deviceId;
                    select.replaceChildren(...devices.map((device, index) => new Option(device.label || `Camera ${index + 1}`, device.deviceId, false, device.deviceId === activeId)));
                    choice.hidden = devices.length < 2;
                    select.disabled = false;
                } catch { /* Default camera can still scan if listing cameras is unavailable. */ }
            } catch (error) {
                if (session !== generation) return;
                stopCamera();
                const messages = {
                    NotAllowedError: 'Camera access was blocked. Allow camera access in your browser settings, then try again.',
                    NotFoundError: 'No camera was found. Connect a webcam and try again, or enter the token below.',
                    NotReadableError: 'The camera is busy or unavailable. Close other apps using it, then try again.',
                    OverconstrainedError: 'That camera is unavailable. Try scanning again with the default camera.',
                    SecurityError: 'Camera access is disabled by your browser. Allow camera access or enter the token below.',
                };
                message(messages[error.name] || 'Unable to start the camera. Check its connection and browser permissions, then try again.', 'error');
            }
        }

        start.addEventListener('click', () => startCamera());
        select.addEventListener('change', () => startCamera(select.value));
        stop.addEventListener('click', () => { stopCamera(); message('Camera stopped. Scan again or enter the token below.'); });
        form.addEventListener('submit', stopCamera);
        window.addEventListener('pagehide', stopCamera);
        document.addEventListener('visibilitychange', () => {
            if (document.hidden && !stop.hidden) { stopCamera(); message('Camera paused while this tab was hidden. Select Scan with camera to resume.'); }
        });
    });
})();
