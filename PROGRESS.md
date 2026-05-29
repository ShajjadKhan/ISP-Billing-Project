# CyberNet ISP System - Progress

## COMPLETED
- [x] Database schema (cybernet.db)
- [x] Main portal (port 8090) - customers, devices, packages, reports
- [x] FreeRADIUS setup on 10.20.30.40
- [x] radius_manager.php - add/remove MACs automatically
- [x] Portal approve/suspend/remove connected to RADIUS
- [x] Captive portal login page (served by Mikrotik)
- [x] submit.php - phone number submission from captive portal
- [x] Walled garden - unauthenticated phones can reach server
- [x] Full end-to-end test PASSED on test Mikrotik (hAP lite)

## NEXT - PRODUCTION WORK
- [ ] Apply to real MK1 and MK2 (10.12.14.1 network)
- [ ] Upload login.html to real Mikrotiks
- [ ] Add walled garden and RADIUS config to real Mikrotiks
- [ ] Make route 10.88.88.0/24 permanent (netplan)
- [ ] Auto-expire packages (cron job removes MAC from RADIUS)
- [ ] Auto-suspend expired customers (cron job)
- [ ] WhatsApp notifications (expiry warning, welcome, blocked)
- [ ] Test with multiple devices per customer
- [ ] Legal compliance report PDF export
- [ ] Dashboard live stats (online now, data usage)
