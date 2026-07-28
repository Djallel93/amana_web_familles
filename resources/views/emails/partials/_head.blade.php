{{-- resources/views/emails/partials/_head.blade.php --}}
{{--
    Recoloré depuis amana_web_planning : palette bleu marine/sky remplacée
    par ambre/terracotta (identité visuelle propre à Familles, section 8.1
    du prompt de migration). Structure CSS identique, seules les valeurs de
    couleur changent — #0c1e2e→#2e1a0f (fond header), #0369a1→#b45309
    (accent), etc.
--}}
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
    :root {
        color-scheme: light only;
    }

    *,
    *::before,
    *::after {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        background-color: #f4f6f8 !important;
        font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
        color: #1a2332;
        -webkit-font-smoothing: antialiased;
        margin: 0 !important;
        padding: 0 !important;
    }

    .arabic {
        font-family: 'Amiri', 'Traditional Arabic', 'Scheherazade New', 'Scheherazade',
            'Arabic Typesetting', 'Noto Naskh Arabic', serif;
    }

    .serif {
        font-family: 'Cormorant Garamond', 'Palatino Linotype', Palatino,
            'Book Antiqua', Georgia, serif;
    }

    /* ── Shell ── */
    .shell {
        background: #f4f6f8;
        padding: 32px 16px 48px;
    }

    .wrapper {
        max-width: 600px;
        margin: 0 auto;
        background: #ffffff;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
    }

    /* ── Header ── */
    .header {
        background: #2e1a0f;
        padding: 40px 32px 36px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .header::before {
        content: '';
        position: absolute;
        top: -80px;
        left: -80px;
        width: 280px;
        height: 280px;
        background: radial-gradient(circle, rgba(180, 83, 9, 0.35) 0%, transparent 65%);
        pointer-events: none;
    }

    .header::after {
        content: '';
        position: absolute;
        bottom: -60px;
        right: -60px;
        width: 220px;
        height: 220px;
        background: radial-gradient(circle, rgba(217, 119, 6, 0.2) 0%, transparent 65%);
        pointer-events: none;
    }

    .bismillah {
        font-size: 26px;
        color: #fbd9ab;
        letter-spacing: 1px;
        margin-bottom: 24px;
        direction: rtl;
        line-height: 1.7;
        position: relative;
        z-index: 1;
    }

    .header-logo {
        width: 72px;
        height: 72px;
        border-radius: 16px;
        overflow: hidden;
        margin: 0 auto 20px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        position: relative;
        z-index: 1;
    }

    .header-logo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .header-brand {
        font-size: 28px;
        font-weight: 700;
        color: #ffffff;
        letter-spacing: 4px;
        text-transform: uppercase;
        margin-bottom: 5px;
        position: relative;
        z-index: 1;
    }

    .header-sub {
        font-size: 10px;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.4);
        font-weight: 500;
        margin-bottom: 22px;
        position: relative;
        z-index: 1;
    }

    .salam {
        font-size: 20px;
        font-weight: 700;
        color: #ffffff;
        direction: rtl;
        line-height: 1.6;
        margin-bottom: 22px;
        position: relative;
        z-index: 1;
    }

    .header-divider {
        display: table;
        width: 100%;
        margin-bottom: 22px;
        position: relative;
        z-index: 1;
    }

    .header-divider td {
        border-top: 1px solid rgba(217, 119, 6, 0.3);
        vertical-align: middle;
    }

    .header-divider .star {
        padding: 0 12px;
        color: rgba(217, 119, 6, 0.7);
        font-size: 14px;
        white-space: nowrap;
    }

    .header-badge {
        display: inline-block;
        background: rgba(217, 119, 6, 0.15);
        border: 1px solid rgba(217, 119, 6, 0.3);
        border-radius: 40px;
        padding: 5px 18px;
        font-size: 10px;
        letter-spacing: 2.5px;
        text-transform: uppercase;
        color: #fbd9ab;
        font-weight: 600;
        margin-bottom: 12px;
        position: relative;
        z-index: 1;
    }

    .header-title {
        font-size: 26px;
        font-weight: 700;
        color: #ffffff;
        line-height: 1.3;
        margin-bottom: 6px;
        position: relative;
        z-index: 1;
    }

    .header-title-sub {
        font-size: 14px;
        color: rgba(255, 255, 255, 0.5);
        font-weight: 300;
        position: relative;
        z-index: 1;
    }

    /* ── Stripe ── */
    .stripe {
        height: 4px;
        background: repeating-linear-gradient(90deg,
                #b45309 0px, #b45309 8px,
                #c2703a 8px, #c2703a 10px,
                #d97706 10px, #d97706 12px,
                #c2703a 12px, #c2703a 14px,
                #b45309 14px, #b45309 22px,
                #ffffff 22px, #ffffff 28px);
    }

    /* ── Body ── */
    .body {
        background: #ffffff;
        padding: 36px 32px;
        border-left: 1px solid #e5e9ef;
        border-right: 1px solid #e5e9ef;
    }

    .greeting {
        font-size: 20px;
        font-weight: 600;
        color: #1a2332;
        margin-bottom: 18px;
        line-height: 1.35;
    }

    .greeting em {
        color: #b45309;
        font-style: italic;
    }

    .body-text {
        font-size: 14.5px;
        line-height: 1.85;
        color: #374151;
        font-weight: 300;
        margin-bottom: 16px;
    }

    .body-text strong {
        color: #78350f;
        font-weight: 600;
    }

    /* ── CTA ── */
    .cta-wrap {
        text-align: center;
        margin: 28px 0;
    }

    .cta-button {
        display: inline-block;
        background: #b45309;
        color: #ffffff !important;
        text-decoration: none;
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 0.3px;
        padding: 14px 32px;
        border-radius: 8px;
        box-shadow: 0 4px 16px rgba(180, 83, 9, 0.35);
    }

    .cta-note {
        font-size: 11.5px;
        color: #9ca3af;
        margin-top: 10px;
        font-weight: 300;
    }

    /* ── Warning box ── */
    .warn-box {
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 8px;
        padding: 13px 16px;
        display: table;
        width: 100%;
        margin: 20px 0;
    }

    .warn-icon {
        display: table-cell;
        font-size: 18px;
        padding-right: 12px;
        vertical-align: top;
        padding-top: 2px;
        white-space: nowrap;
    }

    .warn-text {
        display: table-cell;
        font-size: 13px;
        color: #92400e;
        font-weight: 300;
        line-height: 1.6;
        vertical-align: top;
    }

    .warn-text strong {
        color: #78350f;
        font-weight: 600;
    }

    /* ── Info box (success / green) ── */
    .info-box {
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        border-radius: 8px;
        padding: 16px 18px;
        display: table;
        width: 100%;
        margin: 20px 0;
    }

    .info-icon {
        display: table-cell;
        font-size: 22px;
        padding-right: 14px;
        vertical-align: top;
        padding-top: 2px;
        white-space: nowrap;
    }

    .info-content {
        display: table-cell;
        vertical-align: top;
    }

    .info-title {
        font-size: 14px;
        font-weight: 600;
        color: #065f46;
        margin-bottom: 5px;
    }

    .info-text {
        font-size: 13px;
        color: #047857;
        font-weight: 300;
        line-height: 1.6;
    }

    /* ── Hint box ── */
    .hint-box {
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 8px;
        padding: 13px 16px;
        display: table;
        width: 100%;
        margin: 0 0 20px;
    }

    .hint-icon {
        display: table-cell;
        font-size: 16px;
        padding-right: 10px;
        vertical-align: top;
        padding-top: 2px;
        white-space: nowrap;
    }

    .hint-text {
        display: table-cell;
        font-size: 13px;
        color: #92400e;
        font-weight: 300;
        line-height: 1.6;
        vertical-align: top;
    }

    .hint-text strong {
        color: #78350f;
        font-weight: 600;
    }

    /* ── Features card ── */
    .features-card {
        background: #2e1a0f;
        border-radius: 10px;
        padding: 22px 26px;
        margin: 24px 0;
    }

    .features-label {
        font-size: 9.5px;
        letter-spacing: 2.5px;
        text-transform: uppercase;
        color: #fbd9ab;
        font-weight: 600;
        margin-bottom: 14px;
    }

    .feature-row {
        display: table;
        width: 100%;
        margin-bottom: 12px;
    }

    .feature-row:last-child {
        margin-bottom: 0;
    }

    .feature-icon {
        display: table-cell;
        font-size: 18px;
        width: 28px;
        vertical-align: top;
        padding-top: 1px;
    }

    .feature-text {
        display: table-cell;
        font-size: 13.5px;
        color: #fde8c8;
        font-weight: 300;
        line-height: 1.5;
        vertical-align: top;
    }

    .feature-text strong {
        color: #fbd9ab;
        font-weight: 600;
    }

    /* ── Hadith block ── */
    .hadith-wrap {
        margin: 28px 0;
    }

    .hadith-label {
        font-size: 9.5px;
        letter-spacing: 2.5px;
        text-transform: uppercase;
        color: #b45309;
        font-weight: 600;
        text-align: center;
        margin-bottom: 14px;
    }

    .hadith-card {
        background: #fdf6ec;
        border: 1px solid #f0dcb8;
        border-radius: 10px;
        overflow: hidden;
    }

    .hadith-arabic {
        padding: 24px 24px 18px;
        text-align: justify;
        direction: rtl;
        border-bottom: 1px solid #f0dcb8;
    }

    .hadith-arabic p {
        font-size: 22px;
        line-height: 2.1;
        color: #78350f;
        font-weight: 400;
    }

    .hadith-french {
        padding: 18px 24px 20px;
        border-bottom: 1px solid #f0dcb8;
    }

    .hadith-french p {
        font-style: italic;
        text-align: justify;
        font-size: 14px;
        line-height: 1.85;
        color: #7c4a1e;
        font-weight: 300;
    }

    .hadith-source {
        padding: 12px 24px;
    }

    .hadith-source p {
        font-size: 12px;
        color: #b45309;
        letter-spacing: 0.3px;
    }

    .hadith-source strong {
        color: #78350f;
        font-weight: 600;
    }

    /* ── Closing ── */
    .closing-divider {
        display: table;
        width: 100%;
        margin: 30px 0 24px;
    }

    .closing-divider td {
        border-top: 1px solid #e5e9ef;
        vertical-align: middle;
    }

    .closing-divider .star {
        padding: 0 14px;
        color: #b45309;
        font-size: 13px;
        white-space: nowrap;
    }

    .closing-text {
        font-size: 14.5px;
        line-height: 1.85;
        color: #374151;
        font-weight: 300;
        text-align: center;
        font-style: italic;
        margin-bottom: 22px;
    }

    .closing-text strong {
        color: #b45309;
        font-weight: 600;
        font-style: normal;
    }

    .jazakum {
        text-align: center;
    }

    .jazakum-text {
        font-size: 32px;
        font-weight: 700;
        color: #b45309;
        direction: rtl;
        line-height: 1.6;
        font-family: 'Amiri', 'Traditional Arabic', 'Scheherazade New', serif;
    }

    /* ── Footer ── */
    .footer {
        background: #fdf6ec;
        border: 1px solid #f0dcb8;
        border-top: none;
        padding: 24px 32px;
        text-align: center;
    }

    .footer-logo {
        font-size: 18px;
        font-weight: 700;
        letter-spacing: 4px;
        text-transform: uppercase;
        color: #b45309;
        margin-bottom: 10px;
    }

    .footer p {
        font-size: 11.5px;
        color: #6b7280;
        line-height: 1.9;
        font-weight: 300;
    }

    .footer a {
        color: #b45309;
        text-decoration: none;
    }

    /* ══ MOBILE ══ */
    @media only screen and (max-width: 480px) {
        .shell {
            padding: 0;
        }

        .header {
            padding: 32px 18px 28px;
            border-radius: 0;
        }

        .bismillah {
            font-size: 20px;
        }

        .salam {
            font-size: 16px;
        }

        .header-brand {
            font-size: 22px;
            letter-spacing: 3px;
        }

        .header-title {
            font-size: 20px;
        }

        .body {
            padding: 26px 18px;
            border-left: none;
            border-right: none;
        }

        .features-card {
            padding: 18px;
        }

        .hadith-arabic {
            padding: 18px 16px 14px;
        }

        .hadith-arabic p {
            font-size: 19px;
            line-height: 2;
        }

        .hadith-french {
            padding: 14px 16px 18px;
        }

        .hadith-french p {
            font-size: 13px;
        }

        .hadith-source {
            padding: 10px 16px;
        }

        .footer {
            padding: 20px 18px;
            border-radius: 0;
            border-left: none;
            border-right: none;
            border-bottom: none;
        }

        .warn-box,
        .info-box,
        .hint-box {
            display: block;
        }

        .warn-icon,
        .warn-text,
        .info-icon,
        .info-content,
        .hint-icon,
        .hint-text {
            display: block;
        }

        .warn-icon,
        .hint-icon {
            margin-bottom: 6px;
        }
    }
</style>