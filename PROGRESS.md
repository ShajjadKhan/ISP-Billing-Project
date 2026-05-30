# CyberNet ISP System - Full Build Plan
Last Updated: 2026-05-30

---

## COMPLETED ✅
- Database schema (cybernet.db)
- Main portal (port 8090) - basic customers, devices, packages, reports
- FreeRADIUS setup on 10.20.30.40
- radius_manager.php - add/remove MACs automatically
- Portal approve/suspend/remove/delete connected to RADIUS
- Captive portal login page (served from Mikrotik router)
- submit.php - phone number submission from captive portal
- Walled garden - unauthenticated phones can reach server
- Full end-to-end test PASSED on test Mikrotik (hAP lite)
- MAC format fixed (uppercase with colons)
- UFW ports opened (1812/1813 RADIUS, 8091 captive portal)
- NAT masquerade for hotspot network
- Route to hotspot network via netplan
- Customers page SQL bug fixed
- Dark/light mode working
- Random MAC detection with iPhone/Android instructions
- Delete customer removes MAC from RADIUS immediately
- Billing portal mobile navbar fixed (2-row layout for iPhone 6)

---

## BUGS TO FIX / IMPROVEMENTS NEEDED 🔴
1. Same phone number submitting twice = duplicate customer (should link to existing)
2. Customers page needs full rebuild (see Session B)
3. Phone number format must support international numbers (see Session C)

---

## SESSION A - DASHBOARD REBUILD 🔲
All cards clickable - every number links to filtered data

Row 1 - Stats (ALL clickable):
- Total Customers → customers list (no filter)
- Active Customers → customers filtered active
- Suspended → customers filtered suspended
- Online Right Now → live Mikrotik hotspot active list
- Total Devices → devices list
- Pending Approvals → dedicated approvals page
- Expiring in 3 Days → packages filtered expiring
- Revenue This Month → collections this month

Row 2 - Action tiles (ALL clickable):
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
Always visible per row ONLY:
- Name (phone number small underneath)
- Status badge (active/suspended/expired/extended)
- Registration date
- Expiry date
- Usage (data used)
- Details button → full detail page
- Actions dropdown → renew, suspend/activate, WhatsApp, edit, delete

Detail page tabs (only visible on clicking Details):
- Profile (name, phone+country code, building, room, nationality, iqama)
- Devices (all MACs, online status per device, add/remove)
- Package History
- Payment History
- Connection Logs (every session - start, stop, duration, download, upload, cause)
- WhatsApp History
- Comments

Customer detail shows (like netbillingbd):
- Online/Offline status with duration
- Which router connected on
- IP address
- Signal strength if on fiber (OLT RX power)
- Data used this session and total
- Manual suspend/unsuspend button
- Add device button
- Extend package button

---

## SESSION C - INTERNATIONAL PHONE NUMBERS 🔲
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
- Common expat codes shown first (SA, BD, IN, PK, PH, NP, EG, YE, SD, ET)
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
- Collections by staff breakdown (clickable per staff)
- WhatsApp receipt after payment
- Revenue forecasting (predicted next month based on renewals)

---

## SESSION E - MEDIA SERVER REBUILD 🔲
Database: new media_files table
Fields: id, title, description, category, filename, thumbnail,
        file_size, download_count, uploaded_by, created_at, status

Admin features (inside port 8090):
- Upload file + optional thumbnail
- Title, description, category (Movie/Series/Software/APK)
- Auto thumbnail extraction via ffmpeg if no image uploaded
- Default icons for APK/Software files
- Download stats per file
- Enable/disable/delete
- Storage usage display

Public page (port 8082) - WiFi customers only:
- Grid layout (like Netflix/APKPure)
- Category tabs: All, Movies, Series, Software, APK
- Search bar
- Each card: thumbnail, title, size, download count
- Click card → detail page with poster, description, download button
- Download tracked with customer MAC address
- Bandwidth saved calculated per download

---

## SESSION F - AUTO EXPIRY CRON 🔲
Hourly cron job:
- Check all packages for expiry
- Remove MAC from RADIUS when expired
- Suspend customer in portal automatically
- WhatsApp notification on suspension
- WhatsApp warning 3 days before expiry
- WhatsApp welcome message on new approval
- Make route 10.88.88.0/24 permanent (netplan)

---

## SESSION G - SETTINGS SUB-TABS REBUILD 🔲
Sub-tabs inside settings page:
- General Settings (system name, logo, timezone)
- Mikrotik Settings (IP, API port, credentials, RADIUS secret)
- WhatsApp Settings (OpenWA URL, API key, message templates)
- Login Portal Settings (captive portal page customization)
- After Login Page Settings (what customer sees after connecting)
- Billing Settings (default package days, default fee, grace period)
- OLT Settings (brand, IP, credentials, test connection button)
- Staff & Roles management

---

## SESSION H - OLT INTEGRATION 🔲
Multi-brand support (build in this order):
1. VSOL (current OLT - build first)
2. ZTE
3. Huawei
4. FiberHome
5. BDCOM
6. Generic SNMP fallback for any brand

VSOL V1600GS specifics:
- Web scraping via PHP curl + session cookies
- Login → session → scrape onuauthinfo.html
- ONU list, status, optical info (RX/TX power)
- URL pattern: 192.168.200.200/action/[page].html
- ONU count: 53/56 currently online

Fix needed first (in Session I):
- Add route on server to 192.168.200.0/24 via MK1
- Add firewall rule on MK1 to allow server → OLT

OLT features inside portal:
- All PON ports with ONU count online/offline
- Signal strength per ONU (green/yellow/red)
- Link ONU to customer profile
- Alarm list
- Reboot ONU from portal
- Customer profile shows their fiber signal

---

## SESSION I - APPLY TO REAL MIKROTIKS 🔲
MK1 (10.12.14.1) and MK2:
- Add RADIUS config pointing to 10.12.14.16
- Upload login.html to hotspot directory
- Add walled garden entries (allow server IP)
- Add masquerade rule for hotspot networks
- Add route on server to reach OLT (192.168.200.0/24 via MK1)
- Add MK1 firewall rule: server can reach OLT network
- Full live test with real customers

---

## SESSION J - HOTSPOT PAGE REBUILD 🔲
Pending approvals - dedicated full page:
- Customer name, phone, MAC, IP, time requested
- Approve button → modal with full customer form
- Reject button with reason
- Check if phone already exists → link to existing customer

Active connections list:
- Customer name + phone
- MAC address + device name
- IP address
- Online duration this session
- Data used this session
- Which router/port connected on
- Manual disconnect button
- View customer profile button

---

## SESSION K - USAGE INTELLIGENCE & ACTIVITY TRACKING 🔲
Requires RADIUS accounting data (port 1813) stored to database

Per customer history (like netbillingbd Excel):
- Every session: start time, stop time, duration, download MB, upload MB
- Terminate cause: Lost-Service, Lost-Carrier, Session-Timeout, Admin-Reboot
- Monthly summary per customer
- Export to Excel/PDF (for billing disputes)

Dashboard features:
- Usage ranking board (top 10 data users this month)
- Inactive alert flag (not connected X days)
- Zero usage detection (paid but never connected once)
- Smart renewal prediction (inactive = unlikely to renew)
- Suspicious usage flag (1 device using abnormally high data)
- Peak hour analysis (when is network most busy)

---

## SESSION L - COMPLAINT & TICKETING SYSTEM 🔲
- Customer reports problem from captive portal or WhatsApp
- Admin creates ticket with category, priority
- Assign to specific staff member
- WhatsApp notification to customer on status change
- Resolution time tracking
- Complaint statistics dashboard
- Categories: No internet, Slow speed, Billing issue, Other

---

## SESSION M - EQUIPMENT INVENTORY TRACKING 🔲
- Track ONUs, cables, routers per customer
- Serial numbers, purchase date, warranty expiry
- Which ONU device is at which room/customer
- Lost/damaged/returned tracking
- Stock levels for spare equipment
- Vendor management

---

## SESSION N - VOUCHER & RECHARGE CARD SYSTEM 🔲
- Admin generates batch of voucher codes
- Print as scratch cards (PDF)
- Customer enters code on captive portal
- Auto-activates package linked to voucher
- Track used/unused/expired vouchers
- Distributor portal (let staff sell vouchers)
- Perfect for prepaid cash customers

---

## SESSION O - CUSTOMER SELF-CARE PORTAL 🔲
Customer logs in with phone number + OTP:
- See remaining days and expiry date
- See data used this month
- View payment history
- Raise a complaint/ticket
- Request renewal (notifies admin)
- Download/view invoice
- Available in Arabic and English

---

## SESSION P - RESELLER SYSTEM 🔲
- Create sub-accounts for staff (Riyad, Jahir etc.)
- Each reseller manages assigned customers only
- Reseller sees only their section/floor
- Collections tracked per reseller
- Commission tracking
- Reseller cannot see other resellers' data

---

## SESSION Q - LIVE STREAMING 🔲
Local network live streaming (no internet bandwidth used):
- nginx-rtmp already on port 8086
- Broadcaster uses OBS (PC) or Larix app (phone)
- Push stream to rtmp://10.12.14.16/live
- All WiFi customers watch on browser (HLS player)
- Works on iPhone, Android, PC
- HDMI capture card supported (generic USB, 50-150 SAR)
- Stream title, description, viewer count shown
- Only accessible on WiFi network (not public)
- Admin can start/stop/schedule streams

---

## SESSION R - WHATSAPP SELF-SERVICE BOT 🔲
Customer texts your WhatsApp number:
- "balance" → remaining days and data
- "renew" → payment instructions
- "usage" → this month's usage
- "help" → support contact
- "status" → connection status
Zero other ISP billing software does this via WhatsApp

---

## SESSION S - BUILDING FLOOR MAP 🔲
Visual map of building:
- Each room shown as a box
- Green = active internet
- Red = expired
- Yellow = expiring in 3 days
- Grey = no customer registered
- Click any room → customer details popup
- Admin configures building layout (floors, rooms)
- Unique feature - no competitor has this

---

## SESSION T - REVENUE FORECASTING 🔲
- Based on expiry calendar: predict next month revenue
- Show: X customers expiring, Y% historical renewal rate
- Expected revenue = Z SAR
- Customers unlikely to renew (inactive) flagged separately
- Weekly revenue trend chart
- Compare month over month

---

## UNIQUE FEATURES vs ALL COMPETITORS
Things NO other ISP billing software has:
1. WhatsApp as primary communication (not SMS/email)
2. Local content server + bandwidth savings tracking
3. Local live streaming for events/tournaments
4. Building floor map view (residential ISP specific)
5. Expat multi-language portal (Arabic/Bengali/English/Urdu)
6. Customer activity intelligence + inactive follow-up
7. WhatsApp self-service bot
8. Customer satisfaction rating via WhatsApp
9. Bandwidth saved dashboard card
10. OLT integration in affordable software

---

## TECHNICAL NOTES
- Server: Ubuntu 24.04, IP 10.12.14.16 / Tailscale 100.66.112.67
- ISP Portal: PHP + SQLite3, port 8090
- Captive portal: PHP, port 8091
- Billing portal: PHP + SQLite3, port 8080
- RADIUS: FreeRADIUS port 1812/1813
- ffmpeg installed (for video thumbnails)
- OpenWA: Docker port 2785 (WhatsApp API)
- OLT: VSOL V1600GS at 192.168.200.200
- Test Mikrotik: hAP lite RB941-2nD RouterOS 6.49.13
- Production MK1: 10.12.14.1
- Production MK2: connected via MK1
- Video storage: /mnt/bigstorage/ (458GB)
- GitHub: https://github.com/ShajjadKhan/ISP-Billing-Project
- SCP then push (server DNS blocks GitHub directly)
