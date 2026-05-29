# CyberNet ISP System - Full Build Plan
Last Updated: 2026-05-29

---

## COMPLETED ✅
- Database schema (cybernet.db)
- Main portal (port 8090) - basic customers, devices, packages, reports
- FreeRADIUS setup on 10.20.30.40
- radius_manager.php - add/remove MACs automatically
- Portal approve/suspend/remove connected to RADIUS
- Captive portal login page (served from Mikrotik router)
- submit.php - phone number submission from captive portal
- Walled garden - unauthenticated phones can reach server
- Full end-to-end test PASSED on test Mikrotik (hAP lite)
- MAC format fixed (uppercase with colons)
- UFW ports opened (1812/1813 RADIUS, 8091 captive portal)
- NAT masquerade for hotspot network
- Route to hotspot network via netplan

---

## BUGS TO FIX FIRST 🔴
1. Customers page (port 8090) showing no data
2. Dark/light mode toggle not working
3. Random/private MAC detection on captive portal
   - Detect randomized MAC (2nd hex digit = 2,6,A,E)
   - Block and show message with instructions
   - iPhone and Android instructions shown

---

## SESSION A - DASHBOARD REBUILD 🔲
All cards clickable - every number links to filtered data behind it

Row 1 - Stats (all clickable):
- Total Customers → customers list (no filter)
- Active Customers → customers filtered active
- Suspended → customers filtered suspended
- Online Right Now → live Mikrotik hotspot list
- Total Devices → devices list
- Pending Approvals → dedicated approvals page
- Expiring in 3 Days → packages filtered expiring
- Revenue This Month → collections this month

Row 2 - Action tiles (clickable):
- Pending Approvals → full dedicated approval page
- Expiring in 3 Days → list with one-click renew
- Suspended Customers → list with reactivate button
- Extended Payments → customers on payment extension
- Online Now → live Mikrotik connection list
- Collections This Month → breakdown by staff (click staff = their list)
- Bandwidth Saved → video portal download stats
- Legal Reports → compliance export

---

## SESSION B - CUSTOMERS PAGE REBUILD 🔲
Always visible per row:
- Name (phone number small underneath)
- Status badge (active/suspended/expired/extended)
- Registration date
- Expiry date
- Usage (data used)
- Details button → full detail page
- Actions dropdown → renew, suspend/activate, WhatsApp, edit, delete

Detail page tabs (only on click):
- Profile (name, phone, building, room, nationality, iqama)
- Devices (all MACs, online status, add/remove)
- Package History
- Payment History
- Connection Logs
- WhatsApp History
- Comments

Customer detail shows (like netbillingbd):
- Online/Offline status with duration
- Which router connected on
- IP address
- Signal strength (OLT if fiber)
- Data used this session and total
- Manual suspend/unsuspend
- Add device button
- Extend package button

---

## SESSION C - PHONE NUMBER INTERNATIONAL FIX 🔲
Affects all forms:
- Add customer form
- Edit customer form
- Captive portal login page
- Approval modal
- WhatsApp send functions

Implementation:
- Country code dropdown (flag + code)
- Default: Saudi Arabia +966
- Full international country list
- Stores full number with country code in database
- WhatsApp uses full international format automatically

---

## SESSION D - BILLING & COLLECTIONS TAB 🔲
Full billing inside ISP portal (port 8090):
- Monthly fees per customer
- Partial payment support
- Settle feature (close month permanently)
- Waive feature (enter 0 SAR)
- Extended payment flag (couldn't pay on time, extension given)
- Collections by staff breakdown
- WhatsApp receipt after payment

---

## SESSION E - MEDIA SERVER REBUILD 🔲
Database: new media_files table
Fields: id, title, description, category, filename, thumbnail,
        file_size, download_count, uploaded_by, created_at, status

Admin features (inside port 8090):
- Upload file + optional thumbnail
- Title, description, category (Movie/Series/Software/APK)
- Auto thumbnail extraction via ffmpeg if no image provided
- Default icons for APK/Software
- Download stats per file
- Enable/disable/delete
- Storage usage display

Public page (port 8082) - customers on WiFi only:
- Grid layout (like Netflix/APKPure)
- Category tabs: All, Movies, Series, Software, APK
- Search bar
- Each card: thumbnail, title, size, download count
- Click → detail page with poster, description, download button
- Download tracked with customer MAC
- Bandwidth saved tracked per download

---

## SESSION F - AUTO EXPIRY CRON 🔲
Hourly cron job:
- Check for expired packages
- Remove MAC from RADIUS automatically
- Suspend customer in portal
- Send WhatsApp notification on suspension
- Send WhatsApp warning 3 days before expiry
- Send WhatsApp welcome on new approval

---

## SESSION G - SETTINGS REBUILD 🔲
Sub-tabs inside settings:
- General Settings (system name, logo)
- Mikrotik Settings (IP, API port, credentials, RADIUS secret)
- WhatsApp Settings (OpenWA URL, API key, message templates)
- Login Portal Settings (captive portal page customization)
- After Login Page Settings (what customer sees after connecting)
- Billing Settings (default package days, default fee, grace period)
- OLT Settings (brand, IP, credentials, test connection)
- Staff & Roles management

---

## SESSION H - OLT INTEGRATION 🔲
Multi-brand support:
- VSOL (current - build first)
- ZTE
- Huawei
- FiberHome
- BDCOM
- Generic SNMP fallback

VSOL V1600GS specifics:
- Web scraping via PHP curl + session cookies
- Login → session → scrape onuauthinfo.html
- ONU list, status, optical info (RX/TX power)
- URL pattern: 192.168.200.200/action/[page].html

Fix needed first:
- Add route on server to reach 192.168.200.0/24 via MK1
- Add firewall rule on MK1 (do during Session I)

OLT data to show:
- All PON ports with ONU count
- Online/offline per ONU
- Signal strength (green/yellow/red)
- Link ONU to customer profile
- Alarms list

---

## SESSION I - APPLY TO REAL MIKROTIKS 🔲
MK1 (10.12.14.1) and MK2:
- Add RADIUS config pointing to server
- Upload login.html to hotspot directory
- Add walled garden entries
- Add masquerade rule for hotspot networks
- Add route allowing server to reach OLT (192.168.200.0/24)
- Full live test with real customers

---

## SESSION J - HOTSPOT PAGE REBUILD 🔲
Pending approvals - dedicated page:
- Customer name, phone, MAC, IP, time requested
- Approve button → modal with full customer form
- Reject button with reason
- Bulk approve option

Active connections list:
- Customer name + phone
- MAC address
- IP address
- Online duration
- Data used this session
- Which router/port
- Manual disconnect button
- View customer button

---

## TECHNICAL NOTES
- Server: Ubuntu 24.04, IP 10.12.14.16
- Portal: PHP + SQLite3, port 8090
- Captive portal: port 8091
- RADIUS: FreeRADIUS on port 1812/1813
- ffmpeg installed (for video thumbnails)
- OpenWA running on port 2785 (WhatsApp API)
- OLT: VSOL V1600GS at 192.168.200.200
- Test Mikrotik: hAP lite RB941-2nD, RouterOS 6.49.13
- GitHub: https://github.com/ShajjadKhan/ISP-Billing-Project
