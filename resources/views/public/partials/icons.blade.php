{{-- Inline SVG sprite for the public site. Use: @include('public.partials.i', ['n' => 'car']) --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <defs>
    <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></symbol>
    <symbol id="i-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
    <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-3.4 8.4-8 9-4.6-.6-8-4-8-9V6z"/><path d="m9 12 2 2 4-4"/></symbol>
    <symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></symbol>
    <symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14.5a5 5 0 0 1 5 5"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></symbol>
    <symbol id="i-motor" viewBox="0 0 24 24"><path d="M4 16v-4l2.2-5.2A2 2 0 0 1 8 5.5h8a2 2 0 0 1 1.8 1.3L20 12v4"/><path d="M3 12h18v5H3z"/><circle class="acc" cx="7.5" cy="17.5" r="1.8"/><circle class="acc" cx="16.5" cy="17.5" r="1.8"/></symbol>
    <symbol id="i-health" viewBox="0 0 24 24"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/><path class="acc" d="M7.5 11.5h2.5l1-2 2 4 1-2h2.5"/></symbol>
    <symbol id="i-travel" viewBox="0 0 24 24"><path d="M21 4 3 11l7 3 3 7z"/><path class="acc" d="m10 14 11-10"/></symbol>
    <symbol id="i-home" viewBox="0 0 24 24"><path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path class="acc" d="M10 20v-6h4v6"/></symbol>
    <symbol id="i-business" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5h6v2"/><path class="acc" d="M3 13h18M11 13v2h2v-2"/></symbol>
    <symbol id="i-life" viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-3.4 8.4-8 9-4.6-.6-8-4-8-9V6z"/><path class="acc" d="M12 15.5s-3-1.8-3-4a1.6 1.6 0 0 1 3-.8 1.6 1.6 0 0 1 3 .8c0 2.2-3 4-3 4z"/></symbol>
    <symbol id="i-accident" viewBox="0 0 24 24"><path d="M4 16a8 8 0 0 1 16 0z"/><path d="M2 16h20v3H2z"/><path class="acc" d="M12 8v4M10 10h4"/></symbol>
    <symbol id="i-dots" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/></symbol>
    <symbol id="i-compare" viewBox="0 0 24 24"><path d="M12 4v16M7 20h10M5 7h14"/><path d="M5 7l-3 6a3 3 0 0 0 6 0zM19 7l-3 6a3 3 0 0 0 6 0z"/></symbol>
    <symbol id="i-card" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></symbol>
    <symbol id="i-doc" viewBox="0 0 24 24"><path d="M14 3H6v18h12V7z"/><path d="M14 3v4h4M9 12h6M9 16h6"/></symbol>
    <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M20 11a8 8 0 0 0-14-4.5L4 9M4 4v5h5"/><path d="M4 13a8 8 0 0 0 14 4.5l2-2.5M20 20v-5h-5"/></symbol>
    <symbol id="i-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></symbol>
    <symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/></symbol>
    <symbol id="i-handshake" viewBox="0 0 24 24"><path d="M2 11l4-4 4 2 3-2 4 1 5 3"/><path d="M6 13l4 4a1.5 1.5 0 0 0 2-2M10 15l2 2a1.5 1.5 0 0 0 2-2l-3-3M14 15a1.5 1.5 0 0 0 2-2l-3-3"/><path d="M2 11v3l3 2M22 11v3l-3 2"/></symbol>
    <symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></symbol>
    <symbol id="i-play" viewBox="0 0 24 24"><path d="M5 3.5v17l9.5-8.5z" fill="#34A853" stroke="none"/><path d="M5 3.5l9.5 8.5 3.5-3z" fill="#4285F4" stroke="none"/><path d="M5 20.5l9.5-8.5 3.5 3z" fill="#EA4335" stroke="none"/><path d="M18 9l3 1.8c.8.5.8 1.9 0 2.4L18 15l-3.5-3z" fill="#FBBC04" stroke="none"/></symbol>
    <symbol id="i-apple" viewBox="0 0 24 24"><path fill="currentColor" stroke="none" d="M16.4 12.6c0-2.3 1.9-3.4 2-3.5-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.1-2.8.9-3.5.9s-1.9-.9-3.1-.8A4.6 4.6 0 0 0 4.5 9.8c-1.7 2.9-.4 7.2 1.2 9.5.8 1.1 1.7 2.4 2.9 2.3 1.2 0 1.6-.7 3-.7s1.8.7 3 .7c1.3 0 2.1-1.2 2.9-2.3a10 10 0 0 0 1.3-2.7c-.1 0-2.4-1-2.4-4zM14.2 5.8c.6-.8 1.1-1.9 1-3-.9 0-2.1.6-2.7 1.4-.6.7-1.1 1.8-1 2.9 1 .1 2.1-.5 2.7-1.3z"/></symbol>
    <symbol id="i-mail" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></symbol>
    <symbol id="i-phone" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></symbol>
    <symbol id="i-chat" viewBox="0 0 24 24"><path d="M4 20l1.5-4A8 8 0 1 1 9 19.5z"/></symbol>
    <symbol id="i-bell" viewBox="0 0 24 24"><path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21h4"/></symbol>
    <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
    <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></symbol>
    <symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></symbol>
  </defs>
</svg>
