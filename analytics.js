/**
 * CGM Website & Portal Analytics Tracker
 * Automatically tracks page visits, visitor IDs, device types, and referrers.
 */
(function() {
    'use strict';
    try {
        // Visitor ID persistence
        let visitorId = localStorage.getItem('cgm_visitor_id');
        if (!visitorId) {
            visitorId = 'vid_' + Math.random().toString(36).substring(2, 11) + Date.now().toString(36);
            localStorage.setItem('cgm_visitor_id', visitorId);
        }

        // Device detection
        const ua = navigator.userAgent || '';
        let device = 'desktop';
        if (/iPad|Tablet|(Android(?!.*Mobile))/i.test(ua)) {
            device = 'tablet';
        } else if (/Mobile|Android|iPhone|iPod|BlackBerry|IEMobile|Opera Mini/i.test(ua)) {
            device = 'mobile';
        }

        // Referrer cleanup
        let referrer = document.referrer || '';
        if (referrer) {
            try {
                const refUrl = new URL(referrer);
                if (refUrl.hostname === window.location.hostname) {
                    referrer = 'Internal / Navigation';
                } else {
                    referrer = refUrl.hostname.replace(/^www\./, '');
                }
            } catch (e) {
                referrer = 'External Direct';
            }
        } else {
            referrer = 'Direct / Bookmark';
        }

        const path = window.location.pathname || '/';
        const pageName = path.split('/').pop() || 'index.html';
        const cleanPath = '/' + pageName;

        const payload = {
            action: 'track_visit',
            page: cleanPath,
            title: document.title || cleanPath,
            visitor_id: visitorId,
            device: device,
            referrer: referrer
        };

        const apiEndpoint = 'api.php';

        // Use navigator.sendBeacon if available, fallback to fetch
        if (navigator.sendBeacon) {
            const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
            navigator.sendBeacon(apiEndpoint, blob);
        } else if (window.fetch) {
            fetch(apiEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                keepalive: true
            }).catch(function() {});
        }
    } catch (err) {
        // Silent catch to prevent impacting user experience
    }
})();
