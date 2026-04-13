const express = require('express');
const mysql = require('mysql2');
const archiver = require('archiver');
const crypto = require('crypto');
const app = express();
const port = 3000;

app.use(express.json());
app.use(express.static('public'));

const db = mysql.createConnection({
    host: 'localhost',
    user: 'isp_user',
    password: 'isp_pass',
    database: 'isp_manager'
});

db.connect(err => {
    if (err) {
        console.error('Database connection failed:', err);
        return;
    }
    console.log('Connected to MySQL');
});

app.get('/', (req, res) => res.send('ISP System is running'));

function getAdminRole(adminId, callback) {
    db.query("SELECT role, parent_id, commission_rate FROM admins WHERE id = ? AND deleted_at IS NULL", [adminId], (err, rows) => {
        if (err) return callback(err, null);
        if (rows.length === 0) return callback(new Error('Admin not found'), null);
        callback(null, rows[0]);
    });
}

function updateMonthlyCollection(adminId, price) {
    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth() + 1;
    db.query("INSERT INTO monthly_collections (admin_id, year, month, total_sales, collected, pending) VALUES (?, ?, ?, ?, 0, ?) ON DUPLICATE KEY UPDATE total_sales = total_sales + ?, pending = pending + ?", [adminId, year, month, price, price, price, price], (err) => {
        if (err) console.error('Error updating monthly collection:', err);
    });
}

function generateToken() {
    return crypto.randomBytes(32).toString('hex');
}

// ========== CUSTOMER ENDPOINTS ==========
app.post('/register', (req, res) => {
    const { phone, name, address, admin_id } = req.body;
    const adminId = admin_id || 2;
    const expiry = new Date();
    expiry.setDate(expiry.getDate() + 30);
    const sql = `INSERT INTO customers (admin_id, phone, name, address, expiry_date, status) VALUES (?, ?, ?, ?, ?, 'active')`;
    db.query(sql, [adminId, phone, name, address, expiry], (err, result) => {
        if (err) {
            if (err.code === 'ER_DUP_ENTRY') return res.status(400).json({ error: 'Phone already registered' });
            return res.status(500).json({ error: err.message });
        }
        res.json({ message: 'Customer registered successfully', customerId: result.insertId });
    });
});

app.post('/device/add', (req, res) => {
    const { phone, mac_address, device_name } = req.body;
    db.query("SELECT id FROM customers WHERE phone = ? AND admin_id = 2", [phone], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0) return res.status(404).json({ error: 'Customer not found' });
        const customerId = rows[0].id;
        db.query("SELECT COUNT(*) as cnt FROM devices WHERE customer_id = ?", [customerId], (err, countRes) => {
            if (err) return res.status(500).json({ error: err.message });
            const isFirst = countRes[0].cnt === 0;
            const approved = isFirst ? 1 : 0;
            db.query("INSERT INTO devices (customer_id, mac_address, device_name, is_approved) VALUES (?, ?, ?, ?)", [customerId, mac_address, device_name, approved], (err, result) => {
                if (err) {
                    if (err.code === 'ER_DUP_ENTRY') return res.status(400).json({ error: 'MAC already registered' });
                    return res.status(500).json({ error: err.message });
                }
                res.json({ message: isFirst ? 'Device approved automatically' : 'Device pending approval', deviceId: result.insertId, approved: !!approved });
            });
        });
    });
});

function rechargeWithVoucher(customer, voucher, res) {
    // Get seller info
    db.query("SELECT s.id, s.commission_rate, s.commission_type, s.sub_admin_id, s.admin_id FROM sellers s WHERE s.id = ? AND s.deleted_at IS NULL", [voucher.seller_id], (err, seller) => {
        if (err) return res.status(500).json({ error: err.message });
        if (seller.length === 0) return res.status(400).json({ error: 'Seller not found' });

        const sellerData = seller[0];
        let sellerCommission = 0;
        if (sellerData.commission_type === 'percent') {
            sellerCommission = (voucher.price * sellerData.commission_rate) / 100;
        } else {
            sellerCommission = sellerData.commission_rate;
        }

        // Commission for assigned sub‑admin (if any)
        let subAdminCommission = 0;
        const assignedSubAdminId = customer.assigned_sub_admin_id;
        if (assignedSubAdminId) {
            db.query("SELECT commission_rate FROM admins WHERE id = ? AND role = 'sub_admin'", [assignedSubAdminId], (err, admin) => {
                if (err) return res.status(500).json({ error: err.message });
                if (admin.length) {
                    const subAdminRate = admin[0].commission_rate || 0;
                    subAdminCommission = (voucher.price * subAdminRate) / 100;
                }
                finalizeRecharge();
            });
        } else {
            finalizeRecharge();
        }

        function finalizeRecharge() {
            const totalCommission = sellerCommission + subAdminCommission;

            const currentBalance = parseFloat(customer.balance) || 0;
            const newBalance = currentBalance + parseFloat(voucher.price);

            db.beginTransaction(err => {
                if (err) return res.status(500).json({ error: err.message });
                db.query("UPDATE customers SET balance = ? WHERE id = ?", [newBalance, customer.id], err => {
                    if (err) return db.rollback(() => res.status(500).json({ error: err.message }));
                    db.query("INSERT INTO transactions (customer_id, amount, type, reference) VALUES (?, ?, 'recharge', ?)", [customer.id, voucher.price, voucher.pin_code], err => {
                        if (err) return db.rollback(() => res.status(500).json({ error: err.message }));
                        db.query("UPDATE vouchers SET is_used = 1, used_by_customer_id = ?, used_at = NOW(), commission_earned = ?, sub_admin_commission = ? WHERE id = ?",
                            [customer.id, sellerCommission, subAdminCommission, voucher.id], err => {
                            if (err) return db.rollback(() => res.status(500).json({ error: err.message }));
                            db.commit(err => {
                                if (err) return db.rollback(() => res.status(500).json({ error: err.message }));
                                updateMonthlyCollection(sellerData.admin_id, voucher.price);
                                res.json({ message: 'Recharge successful', new_balance: newBalance });
                            });
                        });
                    });
                });
            });
        }
    });
}

app.post('/recharge', (req, res) => {
    const { phone, pin_code } = req.body;
    db.query("SELECT id, expiry_date, balance, assigned_seller_id, assigned_sub_admin_id FROM customers WHERE phone = ? AND admin_id = 2", [phone], (err, customers) => {
        if (err) return res.status(500).json({ error: err.message });
        if (customers.length === 0) return res.status(404).json({ error: 'Customer not found' });
        const customer = customers[0];
        db.query("SELECT * FROM vouchers WHERE pin_code = ? AND is_used = 0 AND deleted_at IS NULL", [pin_code], (err, vouchers) => {
            if (err) return res.status(500).json({ error: err.message });
            if (vouchers.length === 0) return res.status(400).json({ error: 'Invalid or already used voucher' });
            const voucher = vouchers[0];
            rechargeWithVoucher(customer, voucher, res);
        });
    });
});

app.post('/admin/approve-device', (req, res) => {
    const { device_id } = req.body;
    db.query("UPDATE devices SET is_approved = 1 WHERE id = ? AND is_approved = 0", [device_id], (err, result) => {
        if (err) return res.status(500).json({ error: err.message });
        if (result.affectedRows === 0) return res.status(404).json({ error: 'Device not found or already approved' });
        res.json({ message: 'Device approved' });
    });
});

app.post('/admin/delete-device', (req, res) => {
    const { device_id } = req.body;
    db.query("DELETE FROM devices WHERE id = ?", [device_id], (err, result) => {
        if (err) return res.status(500).json({ error: err.message });
        if (result.affectedRows === 0) return res.status(404).json({ error: 'Device not found' });
        res.json({ message: 'Device deleted' });
    });
});

// ========== AGENT ENDPOINTS ==========
app.post('/agent/login', (req, res) => {
    const { username, password } = req.body;
    db.query("SELECT id, name, admin_id, commission_rate, commission_type, sub_admin_id FROM sellers WHERE username = ? AND password = ? AND deleted_at IS NULL", [username, password], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0) return res.status(401).json({ error: 'Invalid credentials' });
        const agent = rows[0];
        res.json({ message: 'Login successful', agentId: agent.id, name: agent.name, commissionRate: agent.commission_rate, commissionType: agent.commission_type });
    });
});

app.get('/agent/vouchers', (req, res) => {
    const agentId = req.query.agent_id;
    const sql = `SELECT v.id, v.pin_code, v.days_valid, v.price, v.is_used, v.used_by_customer_id, v.used_at, v.commission_earned, c.phone as used_by_phone FROM vouchers v LEFT JOIN customers c ON v.used_by_customer_id = c.id WHERE v.seller_id = ? AND v.deleted_at IS NULL ORDER BY v.created_at DESC`;
    db.query(sql, [agentId], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        res.json(rows);
    });
});

app.post('/agent/request-vouchers', (req, res) => {
    const { agent_id, quantity, days_valid } = req.body;
    db.query("SELECT sub_admin_id FROM sellers WHERE id = ? AND deleted_at IS NULL", [agent_id], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        const subAdminId = rows.length ? rows[0].sub_admin_id : null;
        db.query("INSERT INTO voucher_requests (seller_id, quantity, days_valid, sub_admin_id) VALUES (?, ?, ?, ?)", [agent_id, quantity, days_valid, subAdminId], (err, result) => {
            if (err) return res.status(500).json({ error: err.message });
            res.json({ message: 'Request sent to admin' });
        });
    });
});

app.get('/agent/search-customer', (req, res) => {
    const { q, agent_id } = req.query;
    if (!q) return res.json([]);
    db.query("SELECT admin_id FROM sellers WHERE id = ? AND deleted_at IS NULL", [agent_id], (err, seller) => {
        if (err) return res.status(500).json({ error: err.message });
        if (seller.length === 0) return res.status(404).json({ error: 'Agent not found' });
        const adminId = seller[0].admin_id;
        const searchTerm = `%${q}%`;
        const sql = `
            SELECT DISTINCT c.id, c.phone, c.name, c.address, c.expiry_date, c.status, c.balance,
                   GROUP_CONCAT(d.mac_address) as devices
            FROM customers c
            LEFT JOIN devices d ON d.customer_id = c.id
            WHERE c.admin_id = ? AND (c.phone LIKE ? OR c.name LIKE ? OR d.mac_address LIKE ?)
            GROUP BY c.id
        `;
        db.query(sql, [adminId, searchTerm, searchTerm, searchTerm], (err, rows) => {
            if (err) return res.status(500).json({ error: err.message });
            res.json(rows);
        });
    });
});

app.post('/agent/remote-recharge', (req, res) => {
    const { agent_id, phone, pin_code } = req.body;
    db.query("SELECT admin_id FROM sellers WHERE id = ? AND deleted_at IS NULL", [agent_id], (err, seller) => {
        if (err) return res.status(500).json({ error: err.message });
        if (seller.length === 0) return res.status(404).json({ error: 'Agent not found' });
        const adminId = seller[0].admin_id;
        db.query("SELECT id, expiry_date, balance, assigned_seller_id, assigned_sub_admin_id FROM customers WHERE phone = ? AND admin_id = ?", [phone, adminId], (err, customers) => {
            if (err) return res.status(500).json({ error: err.message });
            if (customers.length === 0) return res.status(404).json({ error: 'Customer not found' });
            const customer = customers[0];
            db.query("SELECT * FROM vouchers WHERE pin_code = ? AND is_used = 0 AND deleted_at IS NULL", [pin_code], (err, vouchers) => {
                if (err) return res.status(500).json({ error: err.message });
                if (vouchers.length === 0) return res.status(400).json({ error: 'Invalid or already used voucher' });
                const voucher = vouchers[0];
                rechargeWithVoucher(customer, voucher, res);
            });
        });
    });
});

app.get('/agent/stats', (req, res) => {
    const agentId = req.query.agent_id;
    const sql = `SELECT 
        COUNT(CASE WHEN is_used = 0 AND deleted_at IS NULL THEN 1 END) as pending,
        COUNT(CASE WHEN is_used = 1 THEN 1 END) as sold,
        SUM(CASE WHEN is_used = 1 THEN price ELSE 0 END) as total_sold_value,
        SUM(CASE WHEN is_used = 1 THEN commission_earned ELSE 0 END) as total_commission
        FROM vouchers WHERE seller_id = ? AND deleted_at IS NULL`;
    db.query(sql, [agentId], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        res.json(rows[0]);
    });
});

app.post('/agent/create-customer', (req, res) => {
    const { agent_id, phone, name, email, address } = req.body;
    db.query("SELECT admin_id FROM sellers WHERE id = ? AND deleted_at IS NULL", [agent_id], (err, seller) => {
        if (err) return res.status(500).json({ error: err.message });
        if (seller.length === 0) return res.status(404).json({ error: 'Agent not found' });
        const adminId = seller[0].admin_id;
        const expiry = new Date();
        expiry.setDate(expiry.getDate() + 30);
        const sql = `INSERT INTO customers (admin_id, phone, name, address, bandwidth_limit, expiry_date, status, created_by_agent_id) 
                     VALUES (?, ?, ?, ?, 0, ?, 'active', ?)`;
        db.query(sql, [adminId, phone, name, address, expiry, agent_id], (err, result) => {
            if (err) {
                if (err.code === 'ER_DUP_ENTRY') return res.status(400).json({ error: 'Phone already registered under this admin' });
                return res.status(500).json({ error: err.message });
            }
            res.json({ message: 'Customer created successfully', customerId: result.insertId });
        });
    });
});

// ========== ADMIN ENDPOINTS ==========
app.post('/admin/login', (req, res) => {
    const { username, password } = req.body;
    db.query("SELECT id, username, role, parent_id, commission_rate FROM admins WHERE username = ? AND password = ? AND role IN ('admin', 'sub_admin') AND deleted_at IS NULL", [username, password], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0) return res.status(401).json({ error: 'Invalid credentials' });
        const admin = rows[0];
        res.json({ message: 'Login successful', adminId: admin.id, role: admin.role, parentId: admin.parent_id, commissionRate: admin.commission_rate });
    });
});

app.get('/admin/dashboard-stats', (req, res) => {
    db.query("UPDATE customers SET status = 'suspended' WHERE expiry_date < NOW() AND status = 'active'", (err) => {
        if (err) console.error('Error updating statuses', err);
    });
    const adminId = req.query.admin_id;
    getAdminRole(adminId, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        let sql;
        if (admin.role === 'admin') {
            sql = `
                SELECT 
                    (SELECT COUNT(*) FROM customers WHERE admin_id = ?) as total_customers,
                    (SELECT COUNT(*) FROM devices WHERE customer_id IN (SELECT id FROM customers WHERE admin_id = ?)) as total_devices,
                    (SELECT COUNT(*) FROM devices WHERE customer_id IN (SELECT id FROM customers WHERE admin_id = ?) AND is_approved = 1) as active_devices,
                    (SELECT COUNT(*) FROM sellers WHERE admin_id = ? AND deleted_at IS NULL) as total_sellers,
                    (SELECT COUNT(*) FROM vouchers WHERE seller_id IN (SELECT id FROM sellers WHERE admin_id = ?) AND deleted_at IS NULL) as total_vouchers,
                    (SELECT COUNT(*) FROM vouchers WHERE seller_id IN (SELECT id FROM sellers WHERE admin_id = ?) AND is_used = 0 AND deleted_at IS NULL) as pending_vouchers,
                    (SELECT COUNT(*) FROM vouchers WHERE seller_id IN (SELECT id FROM sellers WHERE admin_id = ?) AND is_used = 1) as sold_vouchers,
                    (SELECT SUM(price) FROM vouchers WHERE seller_id IN (SELECT id FROM sellers WHERE admin_id = ?) AND is_used = 1) as total_sales_value,
                    (SELECT SUM(commission_earned + sub_admin_commission) FROM vouchers WHERE seller_id IN (SELECT id FROM sellers WHERE admin_id = ?) AND is_used = 1) as total_commission_paid
                FROM dual
            `;
            db.query(sql, [adminId, adminId, adminId, adminId, adminId, adminId, adminId, adminId, adminId], (err, results) => {
                if (err) return res.status(500).json({ error: err.message });
                const data = results[0];
                const amountToCollect = (data.total_sales_value || 0) - (data.total_commission_paid || 0);
                res.json({
                    total_customers: data.total_customers || 0,
                    total_devices: data.total_devices || 0,
                    active_devices: data.active_devices || 0,
                    total_sellers: data.total_sellers || 0,
                    total_vouchers: data.total_vouchers || 0,
                    pending_vouchers: data.pending_vouchers || 0,
                    sold_vouchers: data.sold_vouchers || 0,
                    total_sales_value: data.total_sales_value || 0,
                    amount_to_collect: amountToCollect
                });
            });
        } else {
            const parentId = admin.parent_id;
            sql = `
                SELECT 
                    (SELECT COUNT(DISTINCT c.id) FROM customers c 
                     JOIN sellers s ON c.created_by_agent_id = s.id
                     WHERE c.admin_id = ? AND s.sub_admin_id = ?) as total_customers,
                    (SELECT COUNT(*) FROM devices WHERE customer_id IN (SELECT id FROM customers WHERE admin_id = ?)) as total_devices,
                    (SELECT COUNT(*) FROM devices WHERE customer_id IN (SELECT id FROM customers WHERE admin_id = ?) AND is_approved = 1) as active_devices,
                    (SELECT COUNT(*) FROM sellers WHERE admin_id = ? AND sub_admin_id = ? AND deleted_at IS NULL) as total_sellers,
                    (SELECT COUNT(*) FROM vouchers WHERE seller_id IN (SELECT id FROM sellers WHERE admin_id = ? AND sub_admin_id = ?) AND deleted_at IS NULL) as total_vouchers,
                    (SELECT COUNT(*) FROM vouchers WHERE seller_id IN (SELECT id FROM sellers WHERE admin_id = ? AND sub_admin_id = ?) AND is_used = 0 AND deleted_at IS NULL) as pending_vouchers,
                    (SELECT COUNT(*) FROM vouchers WHERE seller_id IN (SELECT id FROM sellers WHERE admin_id = ? AND sub_admin_id = ?) AND is_used = 1) as sold_vouchers,
                    (SELECT SUM(price) FROM vouchers WHERE seller_id IN (SELECT id FROM sellers WHERE admin_id = ? AND sub_admin_id = ?) AND is_used = 1) as total_sales_value,
                    (SELECT SUM(commission_earned + sub_admin_commission) FROM vouchers WHERE seller_id IN (SELECT id FROM sellers WHERE admin_id = ? AND sub_admin_id = ?) AND is_used = 1) as total_commission_paid
                FROM dual
            `;
            const params = [parentId, adminId, parentId, parentId, parentId, adminId, parentId, adminId, parentId, adminId, parentId, adminId, parentId, adminId, parentId, adminId];
            db.query(sql, params, (err, results) => {
                if (err) return res.status(500).json({ error: err.message });
                const data = results[0];
                const amountToCollect = (data.total_sales_value || 0) - (data.total_commission_paid || 0);
                res.json({
                    total_customers: data.total_customers || 0,
                    total_devices: data.total_devices || 0,
                    active_devices: data.active_devices || 0,
                    total_sellers: data.total_sellers || 0,
                    total_vouchers: data.total_vouchers || 0,
                    pending_vouchers: data.pending_vouchers || 0,
                    sold_vouchers: data.sold_vouchers || 0,
                    total_sales_value: data.total_sales_value || 0,
                    amount_to_collect: amountToCollect
                });
            });
        }
    });
});

app.get('/admin/customers', (req, res) => {
    const adminId = req.query.admin_id;
    getAdminRole(adminId, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        let sql;
        if (admin.role === 'admin') {
            sql = `SELECT c.*, s.name as agent_name, assigned_seller.name as assigned_seller_name, 
                          sub_admin.username as assigned_sub_admin_name, p.name as package_name 
                   FROM customers c 
                   LEFT JOIN sellers s ON c.created_by_agent_id = s.id
                   LEFT JOIN sellers assigned_seller ON c.assigned_seller_id = assigned_seller.id
                   LEFT JOIN admins sub_admin ON c.assigned_sub_admin_id = sub_admin.id
                   LEFT JOIN packages p ON c.package_id = p.id
                   WHERE c.admin_id = ? ORDER BY c.id DESC`;
            db.query(sql, [adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json(rows);
            });
        } else {
            const parentId = admin.parent_id;
            sql = `SELECT c.*, s.name as agent_name, assigned_seller.name as assigned_seller_name,
                          sub_admin.username as assigned_sub_admin_name, p.name as package_name 
                   FROM customers c 
                   LEFT JOIN sellers s ON c.created_by_agent_id = s.id
                   LEFT JOIN sellers assigned_seller ON c.assigned_seller_id = assigned_seller.id
                   LEFT JOIN admins sub_admin ON c.assigned_sub_admin_id = sub_admin.id
                   LEFT JOIN packages p ON c.package_id = p.id
                   WHERE c.admin_id = ? AND c.created_by_agent_id IN (SELECT id FROM sellers WHERE sub_admin_id = ?)
                   ORDER BY c.id DESC`;
            db.query(sql, [parentId, adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json(rows);
            });
        }
    });
});

app.get('/admin/customer/:id', (req, res) => {
    const customerId = req.params.id;
    const adminId = req.query.admin_id;
    db.query("SELECT c.*, p.name as package_name, p.price_per_day FROM customers c LEFT JOIN packages p ON c.package_id = p.id WHERE c.id = ?", [customerId], (err, customer) => {
        if (err) return res.status(500).json({ error: err.message });
        if (customer.length === 0) return res.status(404).json({ error: 'Customer not found' });
        const customerData = customer[0];
        db.query("SELECT v.*, s.name as seller_name FROM vouchers v LEFT JOIN sellers s ON v.seller_id = s.id WHERE v.used_by_customer_id = ? ORDER BY v.used_at DESC", [customerId], (err, vouchers) => {
            if (err) return res.status(500).json({ error: err.message });
            db.query("SELECT * FROM devices WHERE customer_id = ?", [customerId], (err, devices) => {
                if (err) return res.status(500).json({ error: err.message });
                db.query("SELECT * FROM logs WHERE customer_id = ? ORDER BY session_start DESC LIMIT 50", [customerId], (err, logs) => {
                    if (err) return res.status(500).json({ error: err.message });
                    res.json({ customer: customerData, vouchers, devices, logs });
                });
            });
        });
    });
});

app.post('/admin/assign-customer', (req, res) => {
    const { admin_id, customer_id, seller_id } = req.body;
    db.query("SELECT admin_id FROM sellers WHERE id = ? AND deleted_at IS NULL", [seller_id], (err, seller) => {
        if (err) return res.status(500).json({ error: err.message });
        if (seller.length === 0) return res.status(404).json({ error: 'Seller not found' });
        const sellerAdminId = seller[0].admin_id;
        getAdminRole(admin_id, (err, admin) => {
            if (err) return res.status(500).json({ error: err.message });
            let allowed = false;
            if (admin.role === 'admin') {
                allowed = (sellerAdminId === admin_id);
            } else {
                allowed = (sellerAdminId === admin.parent_id) && (seller[0].sub_admin_id === admin_id);
            }
            if (!allowed) return res.status(403).json({ error: 'Not authorized to assign this seller' });
            db.query("UPDATE customers SET assigned_seller_id = ? WHERE id = ?", [seller_id, customer_id], (err) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json({ message: 'Customer assigned to seller' });
            });
        });
    });
});

// Assign customer to a sub‑admin (only top admin)
app.post('/admin/assign-customer-to-subadmin', (req, res) => {
    const { admin_id, customer_id, sub_admin_id } = req.body;
    db.query("SELECT role FROM admins WHERE id = ? AND deleted_at IS NULL", [admin_id], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0 || rows[0].role !== 'admin') {
            return res.status(403).json({ error: 'Only top admin can assign customers to sub admins' });
        }
        if (sub_admin_id) {
            db.query("SELECT id FROM admins WHERE id = ? AND role = 'sub_admin' AND parent_id = ?", [sub_admin_id, admin_id], (err, subRows) => {
                if (err) return res.status(500).json({ error: err.message });
                if (subRows.length === 0) {
                    return res.status(404).json({ error: 'Sub admin not found or not under this admin' });
                }
                doUpdate();
            });
        } else {
            doUpdate();
        }
        function doUpdate() {
            db.query("UPDATE customers SET assigned_sub_admin_id = ? WHERE id = ?", [sub_admin_id || null, customer_id], (err, result) => {
                if (err) return res.status(500).json({ error: err.message });
                if (result.affectedRows === 0) return res.status(404).json({ error: 'Customer not found' });
                res.json({ message: 'Customer assigned to sub admin' });
            });
        }
    });
});

app.get('/admin/sellers', (req, res) => {
    const adminId = req.query.admin_id;
    getAdminRole(adminId, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        if (admin.role === 'admin') {
            const sql = "SELECT id, name, commission_rate, commission_type, sub_admin_id FROM sellers WHERE admin_id = ? AND deleted_at IS NULL";
            db.query(sql, [adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json(rows);
            });
        } else {
            const parentId = admin.parent_id;
            const sql = "SELECT id, name, commission_rate, commission_type, sub_admin_id FROM sellers WHERE admin_id = ? AND sub_admin_id = ? AND deleted_at IS NULL";
            db.query(sql, [parentId, adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json(rows);
            });
        }
    });
});

app.post('/admin/update-agent-commission', (req, res) => {
    const { admin_id, seller_id, commission_rate, commission_type } = req.body;
    db.query("SELECT admin_id, sub_admin_id FROM sellers WHERE id = ? AND deleted_at IS NULL", [seller_id], (err, seller) => {
        if (err) return res.status(500).json({ error: err.message });
        if (seller.length === 0) return res.status(404).json({ error: 'Seller not found' });
        const sellerData = seller[0];
        getAdminRole(admin_id, (err, admin) => {
            if (err) return res.status(500).json({ error: err.message });
            if (admin.role === 'admin') {
                if (sellerData.admin_id !== admin_id) return res.status(403).json({ error: 'Not your seller' });
            } else {
                if (sellerData.sub_admin_id !== admin_id) return res.status(403).json({ error: 'Not your seller' });
            }
            // Ensure seller commission does not exceed sub admin's commission (if any)
            if (sellerData.sub_admin_id) {
                db.query("SELECT commission_rate FROM admins WHERE id = ?", [sellerData.sub_admin_id], (err, adminRow) => {
                    if (err) return res.status(500).json({ error: err.message });
                    const subAdminRate = adminRow[0]?.commission_rate || 0;
                    if (commission_rate > subAdminRate) {
                        return res.status(400).json({ error: 'Seller commission cannot exceed sub admin commission' });
                    }
                    doUpdate();
                });
            } else {
                doUpdate();
            }
            function doUpdate() {
                const sql = "UPDATE sellers SET commission_rate = ?, commission_type = ? WHERE id = ?";
                db.query(sql, [commission_rate, commission_type, seller_id], (err, result) => {
                    if (err) return res.status(500).json({ error: err.message });
                    if (result.affectedRows === 0) return res.status(404).json({ error: 'Seller not found' });
                    res.json({ message: 'Commission settings updated' });
                });
            }
        });
    });
});

app.post('/admin/create-seller', (req, res) => {
    const { admin_id, name, phone, username, password, commission_rate, commission_type } = req.body;
    getAdminRole(admin_id, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        const targetAdminId = admin.role === 'admin' ? admin_id : admin.parent_id;
        const subAdminId = admin.role === 'sub_admin' ? admin_id : null;
        if (subAdminId) {
            db.query("SELECT commission_rate FROM admins WHERE id = ?", [subAdminId], (err, subRow) => {
                if (err) return res.status(500).json({ error: err.message });
                const subRate = subRow[0]?.commission_rate || 0;
                if (commission_rate > subRate) {
                    return res.status(400).json({ error: 'Seller commission cannot exceed sub admin commission' });
                }
                insertSeller();
            });
        } else {
            insertSeller();
        }
        function insertSeller() {
            const sql = `INSERT INTO sellers (admin_id, sub_admin_id, name, phone, username, password, commission_rate, commission_type) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)`;
            db.query(sql, [targetAdminId, subAdminId, name, phone, username, password, commission_rate, commission_type], (err, result) => {
                if (err) {
                    if (err.code === 'ER_DUP_ENTRY') return res.status(400).json({ error: 'Username already exists' });
                    return res.status(500).json({ error: err.message });
                }
                res.json({ message: 'Seller created successfully', id: result.insertId });
            });
        }
    });
});

app.post('/admin/delete-seller', (req, res) => {
    const { admin_id, seller_id } = req.body;
    getAdminRole(admin_id, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        let sql;
        if (admin.role === 'admin') {
            sql = "UPDATE sellers SET deleted_at = NOW() WHERE id = ? AND admin_id = ?";
        } else {
            sql = "UPDATE sellers SET deleted_at = NOW() WHERE id = ? AND sub_admin_id = ?";
        }
        db.query(sql, [seller_id, admin_id], (err, result) => {
            if (err) return res.status(500).json({ error: err.message });
            if (result.affectedRows === 0) return res.status(404).json({ error: 'Seller not found or not yours' });
            res.json({ message: 'Seller deleted' });
        });
    });
});

app.post('/admin/restore-seller', (req, res) => {
    const { admin_id, seller_id } = req.body;
    db.query("SELECT role FROM admins WHERE id = ? AND deleted_at IS NULL", [admin_id], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0 || rows[0].role !== 'admin') return res.status(403).json({ error: 'Only top admin can restore' });
        db.query("UPDATE sellers SET deleted_at = NULL WHERE id = ? AND admin_id = ?", [seller_id, admin_id], (err, result) => {
            if (err) return res.status(500).json({ error: err.message });
            if (result.affectedRows === 0) return res.status(404).json({ error: 'Seller not found' });
            res.json({ message: 'Seller restored' });
        });
    });
});

app.get('/admin/subadmins', (req, res) => {
    const adminId = req.query.admin_id;
    db.query("SELECT id, username, commission_rate, phone, email, created_at FROM admins WHERE parent_id = ? AND role = 'sub_admin' AND deleted_at IS NULL", [adminId], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        res.json(rows);
    });
});

app.get('/admin/deleted-subadmins', (req, res) => {
    const adminId = req.query.admin_id;
    db.query("SELECT role FROM admins WHERE id = ? AND deleted_at IS NULL", [adminId], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0 || rows[0].role !== 'admin') return res.status(403).json({ error: 'Only top admin can view deleted sub admins' });
        db.query("SELECT id, username, deleted_at FROM admins WHERE parent_id = ? AND role = 'sub_admin' AND deleted_at IS NOT NULL", [adminId], (err, rows) => {
            if (err) return res.status(500).json({ error: err.message });
            res.json(rows);
        });
    });
});

app.post('/admin/restore-subadmin', (req, res) => {
    const { admin_id, subadmin_id } = req.body;
    db.query("SELECT role FROM admins WHERE id = ? AND deleted_at IS NULL", [admin_id], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0 || rows[0].role !== 'admin') return res.status(403).json({ error: 'Only top admin can restore' });
        db.query("UPDATE admins SET deleted_at = NULL WHERE id = ? AND parent_id = ?", [subadmin_id, admin_id], (err, result) => {
            if (err) return res.status(500).json({ error: err.message });
            if (result.affectedRows === 0) return res.status(404).json({ error: 'Sub admin not found' });
            res.json({ message: 'Sub admin restored' });
        });
    });
});

app.post('/admin/update-subadmin-commission', (req, res) => {
    const { admin_id, subadmin_id, commission_rate } = req.body;
    db.query("SELECT role FROM admins WHERE id = ? AND deleted_at IS NULL", [admin_id], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0 || rows[0].role !== 'admin') return res.status(403).json({ error: 'Only top admin can update sub admin commission' });
        db.query("UPDATE admins SET commission_rate = ? WHERE id = ? AND role = 'sub_admin' AND parent_id = ?", [commission_rate, subadmin_id, admin_id], (err, result) => {
            if (err) return res.status(500).json({ error: err.message });
            if (result.affectedRows === 0) return res.status(404).json({ error: 'Sub admin not found or not under you' });
            res.json({ message: 'Sub admin commission updated' });
        });
    });
});

app.post('/admin/create-subadmin', (req, res) => {
    const { admin_id, username, password, phone, email, commission_rate } = req.body;
    db.query("SELECT role FROM admins WHERE id = ? AND deleted_at IS NULL", [admin_id], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0 || rows[0].role !== 'admin') return res.status(403).json({ error: 'Only top admin can create sub admins' });
        const sql = `INSERT INTO admins (username, password, role, parent_id, phone, email, commission_rate) 
                     VALUES (?, ?, 'sub_admin', ?, ?, ?, ?)`;
        db.query(sql, [username, password, admin_id, phone, email, commission_rate], (err, result) => {
            if (err) {
                if (err.code === 'ER_DUP_ENTRY') return res.status(400).json({ error: 'Username already exists' });
                return res.status(500).json({ error: err.message });
            }
            res.json({ message: 'Sub admin created', id: result.insertId });
        });
    });
});

app.post('/admin/delete-subadmin', (req, res) => {
    const { admin_id, subadmin_id } = req.body;
    db.query("SELECT role FROM admins WHERE id = ? AND deleted_at IS NULL", [admin_id], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0 || rows[0].role !== 'admin') return res.status(403).json({ error: 'Only top admin can delete sub admin' });
        db.query("UPDATE admins SET deleted_at = NOW() WHERE id = ? AND parent_id = ? AND role = 'sub_admin'", [subadmin_id, admin_id], (err, result) => {
            if (err) return res.status(500).json({ error: err.message });
            if (result.affectedRows === 0) return res.status(404).json({ error: 'Sub admin not found or not under you' });
            res.json({ message: 'Sub admin deleted' });
        });
    });
});

app.get('/admin/vouchers', (req, res) => {
    const adminId = req.query.admin_id;
    getAdminRole(adminId, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        let sql;
        if (admin.role === 'admin') {
            sql = `SELECT v.*, s.name as seller_name, c.phone as used_by_phone
                   FROM vouchers v
                   JOIN sellers s ON v.seller_id = s.id
                   LEFT JOIN customers c ON v.used_by_customer_id = c.id
                   WHERE s.admin_id = ? AND v.deleted_at IS NULL
                   ORDER BY v.created_at DESC`;
            db.query(sql, [adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json(rows);
            });
        } else {
            const parentId = admin.parent_id;
            sql = `SELECT v.*, s.name as seller_name, c.phone as used_by_phone
                   FROM vouchers v
                   JOIN sellers s ON v.seller_id = s.id
                   LEFT JOIN customers c ON v.used_by_customer_id = c.id
                   WHERE s.admin_id = ? AND s.sub_admin_id = ? AND v.deleted_at IS NULL
                   ORDER BY v.created_at DESC`;
            db.query(sql, [parentId, adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json(rows);
            });
        }
    });
});

app.post('/admin/create-vouchers', (req, res) => {
    const { admin_id, seller_id, quantity, days_valid, price } = req.body;
    db.query("SELECT admin_id, sub_admin_id FROM sellers WHERE id = ? AND deleted_at IS NULL", [seller_id], (err, seller) => {
        if (err) return res.status(500).json({ error: err.message });
        if (seller.length === 0) return res.status(404).json({ error: 'Seller not found' });
        const sellerData = seller[0];
        getAdminRole(admin_id, (err, admin) => {
            if (err) return res.status(500).json({ error: err.message });
            if (admin.role === 'admin') {
                if (sellerData.admin_id !== admin_id) return res.status(403).json({ error: 'Not your seller' });
            } else {
                if (sellerData.sub_admin_id !== admin_id) return res.status(403).json({ error: 'Not your seller' });
            }
            const vouchers = [];
            const voucherPrice = price !== undefined ? price : 0;
            for (let i = 0; i < quantity; i++) {
                const pin = Math.random().toString(36).substring(2, 8).toUpperCase() + Math.random().toString(36).substring(2, 6).toUpperCase();
                vouchers.push([seller_id, pin, days_valid, voucherPrice]);
            }
            const sql = "INSERT INTO vouchers (seller_id, pin_code, days_valid, price) VALUES ?";
            db.query(sql, [vouchers], (err) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json({ message: `${quantity} vouchers created for seller` });
            });
        });
    });
});

app.post('/admin/delete-vouchers', (req, res) => {
    const { admin_id, voucher_ids } = req.body;
    if (!voucher_ids || !voucher_ids.length) return res.status(400).json({ error: 'No vouchers selected' });
    db.query("SELECT id FROM vouchers WHERE id IN (?) AND is_used = 1", [voucher_ids], (err, used) => {
        if (err) return res.status(500).json({ error: err.message });
        if (used.length > 0) return res.status(400).json({ error: 'Cannot delete used vouchers' });
        getAdminRole(admin_id, (err, admin) => {
            if (err) return res.status(500).json({ error: err.message });
            let sql;
            if (admin.role === 'admin') {
                sql = "UPDATE vouchers SET deleted_at = NOW() WHERE id IN (?) AND seller_id IN (SELECT id FROM sellers WHERE admin_id = ?)";
            } else {
                sql = "UPDATE vouchers SET deleted_at = NOW() WHERE id IN (?) AND seller_id IN (SELECT id FROM sellers WHERE sub_admin_id = ?)";
            }
            db.query(sql, [voucher_ids, admin_id], (err, result) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json({ message: `${result.affectedRows} vouchers deleted` });
            });
        });
    });
});

app.post('/admin/delete-used-vouchers', (req, res) => {
    const { admin_id, voucher_ids, confirmation_code } = req.body;
    if (confirmation_code !== '1234') {
        return res.status(403).json({ error: 'Invalid confirmation code' });
    }
    if (!voucher_ids || !voucher_ids.length) {
        return res.status(400).json({ error: 'No vouchers selected' });
    }
    getAdminRole(admin_id, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        let sql;
        if (admin.role === 'admin') {
            sql = "UPDATE vouchers SET deleted_at = NOW() WHERE id IN (?) AND seller_id IN (SELECT id FROM sellers WHERE admin_id = ?)";
        } else {
            sql = "UPDATE vouchers SET deleted_at = NOW() WHERE id IN (?) AND seller_id IN (SELECT id FROM sellers WHERE sub_admin_id = ?)";
        }
        db.query(sql, [voucher_ids, admin_id], (err, result) => {
            if (err) return res.status(500).json({ error: err.message });
            res.json({ message: `${result.affectedRows} used vouchers deleted` });
        });
    });
});

app.get('/admin/devices', (req, res) => {
    const adminId = req.query.admin_id;
    getAdminRole(adminId, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        let sql;
        if (admin.role === 'admin') {
            sql = `SELECT d.*, c.name as customer_name, c.phone
                   FROM devices d
                   JOIN customers c ON d.customer_id = c.id
                   WHERE c.admin_id = ?
                   ORDER BY d.id DESC`;
            db.query(sql, [adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json(rows);
            });
        } else {
            const parentId = admin.parent_id;
            sql = `SELECT d.*, c.name as customer_name, c.phone
                   FROM devices d
                   JOIN customers c ON d.customer_id = c.id
                   WHERE c.admin_id = ? AND c.created_by_agent_id IN (SELECT id FROM sellers WHERE sub_admin_id = ?)
                   ORDER BY d.id DESC`;
            db.query(sql, [parentId, adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json(rows);
            });
        }
    });
});

app.get('/admin/pending-devices', (req, res) => {
    const adminId = req.query.admin_id;
    getAdminRole(adminId, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        let sql;
        if (admin.role === 'admin') {
            sql = `SELECT d.id, d.mac_address, d.device_name, d.created_at, c.name, c.phone
                   FROM devices d
                   JOIN customers c ON d.customer_id = c.id
                   WHERE c.admin_id = ? AND d.is_approved = 0`;
            db.query(sql, [adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json(rows);
            });
        } else {
            const parentId = admin.parent_id;
            sql = `SELECT d.id, d.mac_address, d.device_name, d.created_at, c.name, c.phone
                   FROM devices d
                   JOIN customers c ON d.customer_id = c.id
                   WHERE c.admin_id = ? AND d.is_approved = 0 AND c.created_by_agent_id IN (SELECT id FROM sellers WHERE sub_admin_id = ?)`;
            db.query(sql, [parentId, adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json(rows);
            });
        }
    });
});

app.get('/admin/voucher-requests', (req, res) => {
    const adminId = req.query.admin_id;
    getAdminRole(adminId, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        let sql;
        if (admin.role === 'admin') {
            sql = `SELECT r.*, s.name as seller_name
                   FROM voucher_requests r
                   JOIN sellers s ON r.seller_id = s.id
                   WHERE s.admin_id = ? AND r.status = 'pending'`;
            db.query(sql, [adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json(rows);
            });
        } else {
            const parentId = admin.parent_id;
            sql = `SELECT r.*, s.name as seller_name
                   FROM voucher_requests r
                   JOIN sellers s ON r.seller_id = s.id
                   WHERE s.admin_id = ? AND s.sub_admin_id = ? AND r.status = 'pending'`;
            db.query(sql, [parentId, adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json(rows);
            });
        }
    });
});

app.post('/admin/approve-voucher-request', (req, res) => {
    const { request_id, admin_id, price } = req.body;
    db.query("SELECT seller_id, quantity, days_valid FROM voucher_requests WHERE id = ? AND status = 'pending'", [request_id], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0) return res.status(404).json({ error: 'Request not found or already processed' });
        const reqData = rows[0];
        db.query("SELECT admin_id, sub_admin_id FROM sellers WHERE id = ? AND deleted_at IS NULL", [reqData.seller_id], (err, seller) => {
            if (err) return res.status(500).json({ error: err.message });
            if (seller.length === 0) return res.status(404).json({ error: 'Seller not found' });
            const sellerData = seller[0];
            getAdminRole(admin_id, (err, admin) => {
                if (err) return res.status(500).json({ error: err.message });
                if (admin.role === 'admin') {
                    if (sellerData.admin_id !== admin_id) return res.status(403).json({ error: 'Not your seller' });
                } else {
                    if (sellerData.sub_admin_id !== admin_id) return res.status(403).json({ error: 'Not your seller' });
                }
                db.beginTransaction(err => {
                    if (err) return res.status(500).json({ error: err.message });
                    const vouchers = [];
                    const voucherPrice = price !== undefined ? price : 0;
                    for (let i = 0; i < reqData.quantity; i++) {
                        const pin = Math.random().toString(36).substring(2, 8).toUpperCase() + Math.random().toString(36).substring(2, 6).toUpperCase();
                        vouchers.push([reqData.seller_id, pin, reqData.days_valid, voucherPrice]);
                    }
                    const sql = "INSERT INTO vouchers (seller_id, pin_code, days_valid, price) VALUES ?";
                    db.query(sql, [vouchers], (err) => {
                        if (err) return db.rollback(() => res.status(500).json({ error: err.message }));
                        db.query("UPDATE voucher_requests SET status = 'approved' WHERE id = ?", [request_id], (err) => {
                            if (err) return db.rollback(() => res.status(500).json({ error: err.message }));
                            db.commit(err => {
                                if (err) return db.rollback(() => res.status(500).json({ error: err.message }));
                                res.json({ message: `Approved: ${reqData.quantity} vouchers created (price ${voucherPrice})` });
                            });
                        });
                    });
                });
            });
        });
    });
});

app.get('/admin/suspended-customers', (req, res) => {
    const adminId = req.query.admin_id;
    getAdminRole(adminId, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        let sql;
        if (admin.role === 'admin') {
            sql = `SELECT c.*, s.name as agent_name, assigned_seller.name as assigned_seller_name 
                   FROM customers c 
                   LEFT JOIN sellers s ON c.created_by_agent_id = s.id
                   LEFT JOIN sellers assigned_seller ON c.assigned_seller_id = assigned_seller.id
                   WHERE c.admin_id = ? AND c.status = 'suspended'`;
            db.query(sql, [adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json(rows);
            });
        } else {
            const parentId = admin.parent_id;
            sql = `SELECT c.*, s.name as agent_name, assigned_seller.name as assigned_seller_name 
                   FROM customers c 
                   LEFT JOIN sellers s ON c.created_by_agent_id = s.id
                   LEFT JOIN sellers assigned_seller ON c.assigned_seller_id = assigned_seller.id
                   WHERE c.admin_id = ? AND c.status = 'suspended' 
                   AND c.created_by_agent_id IN (SELECT id FROM sellers WHERE sub_admin_id = ?)`;
            db.query(sql, [parentId, adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json(rows);
            });
        }
    });
});

app.get('/admin/monthly-closing', (req, res) => {
    const { admin_id, year, month } = req.query;
    getAdminRole(admin_id, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        const targetAdminId = admin.role === 'admin' ? admin_id : admin.parent_id;
        db.query("SELECT total_sales, collected, pending FROM monthly_collections WHERE admin_id = ? AND year = ? AND month = ?", [targetAdminId, year, month], (err, rows) => {
            if (err) return res.status(500).json({ error: err.message });
            if (rows.length === 0) {
                res.json({ total_sales: 0, collected: 0, pending: 0 });
            } else {
                res.json(rows[0]);
            }
        });
    });
});

app.post('/admin/record-collection', (req, res) => {
    const { admin_id, year, month, amount } = req.body;
    getAdminRole(admin_id, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        const targetAdminId = admin.role === 'admin' ? admin_id : admin.parent_id;
        db.query("UPDATE monthly_collections SET collected = collected + ?, pending = pending - ? WHERE admin_id = ? AND year = ? AND month = ?", [amount, amount, targetAdminId, year, month], (err) => {
            if (err) return res.status(500).json({ error: err.message });
            res.json({ message: 'Collection recorded' });
        });
    });
});

app.get('/admin/pending-payments', (req, res) => {
    const adminId = req.query.admin_id;
    getAdminRole(adminId, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        let sql;
        if (admin.role === 'admin') {
            sql = `
                SELECT 
                    s.id, s.name,
                    COALESCE(SUM(v.price), 0) as total_sales,
                    COALESCE(SUM(v.commission_earned), 0) as seller_commission,
                    COALESCE(SUM(v.sub_admin_commission), 0) as sub_admin_commission,
                    COALESCE(SUM(sc.amount), 0) as collected
                FROM sellers s
                LEFT JOIN vouchers v ON v.seller_id = s.id AND v.is_used = 1 AND v.deleted_at IS NULL
                LEFT JOIN seller_collections sc ON sc.seller_id = s.id
                WHERE s.admin_id = ? AND s.deleted_at IS NULL
                GROUP BY s.id
            `;
            db.query(sql, [adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                const result = rows.map(r => ({
                    id: r.id,
                    name: r.name,
                    total_sales: r.total_sales || 0,
                    seller_commission: r.seller_commission || 0,
                    sub_admin_commission: r.sub_admin_commission || 0,
                    collected: r.collected || 0,
                    admin_share: (r.total_sales || 0) - (r.seller_commission || 0) - (r.sub_admin_commission || 0),
                    pending: ((r.total_sales || 0) - (r.seller_commission || 0) - (r.sub_admin_commission || 0)) - (r.collected || 0)
                }));
                res.json(result);
            });
        } else {
            const parentId = admin.parent_id;
            sql = `
                SELECT 
                    s.id, s.name,
                    COALESCE(SUM(v.price), 0) as total_sales,
                    COALESCE(SUM(v.commission_earned), 0) as seller_commission,
                    COALESCE(SUM(v.sub_admin_commission), 0) as sub_admin_commission,
                    COALESCE(SUM(sc.amount), 0) as collected
                FROM sellers s
                LEFT JOIN vouchers v ON v.seller_id = s.id AND v.is_used = 1 AND v.deleted_at IS NULL
                LEFT JOIN seller_collections sc ON sc.seller_id = s.id
                WHERE s.admin_id = ? AND s.sub_admin_id = ? AND s.deleted_at IS NULL
                GROUP BY s.id
            `;
            db.query(sql, [parentId, adminId], (err, rows) => {
                if (err) return res.status(500).json({ error: err.message });
                const result = rows.map(r => ({
                    id: r.id,
                    name: r.name,
                    total_sales: r.total_sales || 0,
                    seller_commission: r.seller_commission || 0,
                    sub_admin_commission: r.sub_admin_commission || 0,
                    collected: r.collected || 0,
                    admin_share: (r.total_sales || 0) - (r.seller_commission || 0) - (r.sub_admin_commission || 0),
                    pending: ((r.total_sales || 0) - (r.seller_commission || 0) - (r.sub_admin_commission || 0)) - (r.collected || 0)
                }));
                res.json(result);
            });
        }
    });
});

app.post('/admin/record-seller-collection', (req, res) => {
    const { admin_id, seller_id, amount } = req.body;
    getAdminRole(admin_id, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        let checkSql;
        if (admin.role === 'admin') {
            checkSql = "SELECT id FROM sellers WHERE id = ? AND admin_id = ?";
        } else {
            checkSql = "SELECT id FROM sellers WHERE id = ? AND sub_admin_id = ?";
        }
        db.query(checkSql, [seller_id, admin_id], (err, rows) => {
            if (err) return res.status(500).json({ error: err.message });
            if (rows.length === 0) return res.status(403).json({ error: 'Seller not under your control' });
            const insertSql = "INSERT INTO seller_collections (seller_id, amount, collected_at) VALUES (?, ?, NOW())";
            db.query(insertSql, [seller_id, amount], (err) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json({ message: 'Collection recorded' });
            });
        });
    });
});

app.get('/admin/servers', (req, res) => {
    const adminId = req.query.admin_id;
    db.query("SELECT id, name, ip_address, port, username, password, secret, status, last_seen FROM mikrotik_servers WHERE admin_id = ?", [adminId], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        res.json(rows);
    });
});

app.post('/admin/servers', (req, res) => {
    const { admin_id, name, ip_address, port, username, password, secret, status } = req.body;
    const sql = "INSERT INTO mikrotik_servers (admin_id, name, ip_address, port, username, password, secret, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    db.query(sql, [admin_id, name, ip_address, port || 8728, username, password, secret, status || 'active'], (err, result) => {
        if (err) return res.status(500).json({ error: err.message });
        res.json({ message: 'Server added', id: result.insertId });
    });
});

app.put('/admin/servers/:id', (req, res) => {
    const { admin_id } = req.body;
    const serverId = req.params.id;
    const fields = [];
    const values = [];
    for (const [key, value] of Object.entries(req.body)) {
        if (key !== 'admin_id') {
            fields.push(`${key}=?`);
            values.push(value);
        }
    }
    if (fields.length === 0) return res.status(400).json({ error: 'No fields to update' });
    values.push(serverId, admin_id);
    const sql = `UPDATE mikrotik_servers SET ${fields.join(', ')} WHERE id = ? AND admin_id = ?`;
    db.query(sql, values, (err, result) => {
        if (err) return res.status(500).json({ error: err.message });
        if (result.affectedRows === 0) return res.status(404).json({ error: 'Server not found or not yours' });
        res.json({ message: 'Server updated' });
    });
});

app.delete('/admin/servers/:id', (req, res) => {
    const { admin_id } = req.body;
    const serverId = req.params.id;
    db.query("DELETE FROM mikrotik_servers WHERE id = ? AND admin_id = ?", [serverId, admin_id], (err, result) => {
        if (err) return res.status(500).json({ error: err.message });
        if (result.affectedRows === 0) return res.status(404).json({ error: 'Server not found or not yours' });
        res.json({ message: 'Server deleted' });
    });
});

app.post('/admin/servers/:id/test', (req, res) => {
    const { admin_id } = req.body;
    const serverId = req.params.id;
    db.query("SELECT ip_address, port FROM mikrotik_servers WHERE id = ? AND admin_id = ?", [serverId, admin_id], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0) return res.status(404).json({ error: 'Server not found' });
        const server = rows[0];
        const net = require('net');
        const client = new net.Socket();
        const timeout = 5000;
        client.setTimeout(timeout);
        client.connect(server.port, server.ip_address, () => {
            client.destroy();
            res.json({ message: 'Connection successful' });
        });
        client.on('error', (err) => {
            res.status(400).json({ error: `Connection failed: ${err.message}` });
        });
        client.on('timeout', () => {
            client.destroy();
            res.status(400).json({ error: 'Connection timeout' });
        });
    });
});

app.post('/admin/generate-hotspot', (req, res) => {
    const archive = archiver('zip', { zlib: { level: 9 } });
    res.attachment('hotspot_files.zip');
    archive.pipe(res);

    const loginHtml = `<!DOCTYPE html>
<html>
<head>
    <title>Internet Login</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial; background: #f0f2f5; text-align: center; padding: 50px; }
        .login-box { max-width: 400px; margin: auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ccc; border-radius: 5px; }
        button { background: #3b82f6; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        .error { color: red; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Login to Internet</h2>
        <form method="post" action="$(link-login-only)" name="login">
            <input type="text" name="username" placeholder="Phone Number" required>
            <input type="password" name="password" placeholder="Voucher Code (optional)">
            <button type="submit">Login</button>
            <div class="error">$(error)</div>
        </form>
        <hr>
        <p><a href="$(link-login-only)?dst=$(link-orig-esc)&popup=true">Use voucher</a></p>
    </div>
</body>
</html>`;

    const statusHtml = `<!DOCTYPE html>
<html>
<head>
    <title>Status</title>
</head>
<body>
    <h2>Connection Status</h2>
    <p>You are logged in as $(username).</p>
    <p>IP: $(ip)</p>
    <p>Uptime: $(uptime)</p>
    <p>Bytes in/out: $(bytes-in-nice) / $(bytes-out-nice)</p>
    <a href="$(link-logout)">Logout</a>
</body>
</html>`;

    const errorsTxt = `radius-timeout=Sorry, authentication server is busy.
radius-reject=Invalid phone number or voucher.
session-limit=You are already logged in.
mac-auth-failed=MAC authentication failed.
`;

    const styleCss = `body { font-family: Arial; background: #f0f2f5; } .login-box { background: white; padding: 20px; border-radius: 10px; }`;

    archive.append(loginHtml, { name: 'login.html' });
    archive.append(statusHtml, { name: 'status.html' });
    archive.append(errorsTxt, { name: 'errors.txt' });
    archive.append(styleCss, { name: 'style.css' });
    archive.append('<html><body>Redirecting...</body></html>', { name: 'redirect.html' });

    archive.finalize();
});

app.post('/admin/create-customer', (req, res) => {
    const { admin_id, phone, name, email, address, bandwidth_limit, expiry_days } = req.body;
    getAdminRole(admin_id, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        const targetAdminId = admin.role === 'admin' ? admin_id : admin.parent_id;
        const expiry = new Date();
        expiry.setDate(expiry.getDate() + (expiry_days || 30));
        const bw = bandwidth_limit === undefined ? 0 : bandwidth_limit;
        const sql = `INSERT INTO customers (admin_id, phone, name, address, bandwidth_limit, expiry_date, status) VALUES (?, ?, ?, ?, ?, ?, 'active')`;
        db.query(sql, [targetAdminId, phone, name, address, bw, expiry], (err, result) => {
            if (err) {
                if (err.code === 'ER_DUP_ENTRY') return res.status(400).json({ error: 'Phone already registered under this admin' });
                return res.status(500).json({ error: err.message });
            }
            res.json({ message: 'Customer created successfully', customerId: result.insertId });
        });
    });
});

app.post('/admin/extend-customer', (req, res) => {
    const { admin_id, customer_id, days } = req.body;
    db.query("SELECT admin_id, status FROM customers WHERE id = ?", [customer_id], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0) return res.status(404).json({ error: 'Customer not found' });
        const customer = rows[0];
        getAdminRole(admin_id, (err, admin) => {
            if (err) return res.status(500).json({ error: err.message });
            if (admin.role === 'admin') {
                if (customer.admin_id !== admin_id) return res.status(403).json({ error: 'Not your customer' });
            } else {
                db.query("SELECT id FROM customers WHERE id = ? AND admin_id = ? AND created_by_agent_id IN (SELECT id FROM sellers WHERE sub_admin_id = ?)", [customer_id, admin.parent_id, admin_id], (err, check) => {
                    if (err) return res.status(500).json({ error: err.message });
                    if (check.length === 0) return res.status(403).json({ error: 'Not your customer' });
                });
            }
            const sql = "UPDATE customers SET expiry_date = DATE_ADD(expiry_date, INTERVAL ? DAY), status = 'active' WHERE id = ?";
            db.query(sql, [days, customer_id], (err, result) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json({ message: `Expiry extended by ${days} days` });
            });
        });
    });
});

app.get('/customers', (req, res) => {
    const adminId = req.query.admin_id || 2;
    db.query("SELECT * FROM customers WHERE admin_id = ?", [adminId], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        res.json(rows);
    });
});

app.get('/devices', (req, res) => {
    db.query("SELECT d.*, c.phone, c.name FROM devices d JOIN customers c ON d.customer_id = c.id", (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        res.json(rows);
    });
});

app.post('/admin/assign-seller-to-subadmin', (req, res) => {
    const { admin_id, seller_id, sub_admin_id } = req.body;
    db.query("SELECT role FROM admins WHERE id = ? AND deleted_at IS NULL", [admin_id], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0 || rows[0].role !== 'admin') {
            return res.status(403).json({ error: 'Only top admin can assign sellers to sub admins' });
        }
        if (sub_admin_id) {
            db.query("SELECT id FROM admins WHERE id = ? AND role = 'sub_admin' AND parent_id = ?", [sub_admin_id, admin_id], (err, subRows) => {
                if (err) return res.status(500).json({ error: err.message });
                if (subRows.length === 0) {
                    return res.status(404).json({ error: 'Sub admin not found or not under this admin' });
                }
                doUpdate();
            });
        } else {
            doUpdate();
        }
        function doUpdate() {
            db.query("UPDATE sellers SET sub_admin_id = ? WHERE id = ? AND admin_id = ?", [sub_admin_id || null, seller_id, admin_id], (err, result) => {
                if (err) return res.status(500).json({ error: err.message });
                if (result.affectedRows === 0) {
                    return res.status(404).json({ error: 'Seller not found or not under this admin' });
                }
                res.json({ message: 'Seller assigned to sub admin' });
            });
        }
    });
});

// ========== CUSTOMER SELF-CARE ENDPOINTS ==========
app.post('/customer/login', (req, res) => {
    const { phone, pin } = req.body;
    db.query("SELECT id, name, phone, balance, expiry_date, status, pin FROM customers WHERE phone = ? AND admin_id = 2", [phone], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0) return res.status(404).json({ error: 'Customer not found' });
        const customer = rows[0];
        if (!customer.pin) {
            db.query("UPDATE customers SET pin = ? WHERE id = ?", [pin, customer.id], (err) => {
                if (err) return res.status(500).json({ error: err.message });
                const token = generateToken();
                const expires = new Date();
                expires.setDate(expires.getDate() + 30);
                db.query("INSERT INTO customer_tokens (customer_id, token, expires_at) VALUES (?, ?, ?)", [customer.id, token, expires], (err) => {
                    if (err) return res.status(500).json({ error: err.message });
                    res.json({ token, customer: { id: customer.id, name: customer.name, phone: customer.phone, balance: customer.balance, expiry: customer.expiry_date, status: customer.status } });
                });
            });
        } else {
            if (customer.pin !== pin) return res.status(401).json({ error: 'Invalid PIN' });
            const token = generateToken();
            const expires = new Date();
            expires.setDate(expires.getDate() + 30);
            db.query("INSERT INTO customer_tokens (customer_id, token, expires_at) VALUES (?, ?, ?)", [customer.id, token, expires], (err) => {
                if (err) return res.status(500).json({ error: err.message });
                res.json({ token, customer: { id: customer.id, name: customer.name, phone: customer.phone, balance: customer.balance, expiry: customer.expiry_date, status: customer.status } });
            });
        }
    });
});

app.get('/customer/dashboard', (req, res) => {
    const token = req.headers.authorization?.split(' ')[1];
    if (!token) return res.status(401).json({ error: 'No token' });
    db.query("SELECT customer_id FROM customer_tokens WHERE token = ? AND expires_at > NOW()", [token], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0) return res.status(401).json({ error: 'Invalid or expired token' });
        const customerId = rows[0].customer_id;
        db.query("SELECT id, name, phone, balance, expiry_date, status FROM customers WHERE id = ?", [customerId], (err, customer) => {
            if (err) return res.status(500).json({ error: err.message });
            if (customer.length === 0) return res.status(404).json({ error: 'Customer not found' });
            const c = customer[0];
            res.json({
                id: c.id,
                name: c.name,
                phone: c.phone,
                balance: c.balance,
                expiry: c.expiry_date,
                status: c.status
            });
        });
    });
});

app.get('/customer/recharge-history', (req, res) => {
    const token = req.headers.authorization?.split(' ')[1];
    if (!token) return res.status(401).json({ error: 'No token' });
    db.query("SELECT customer_id FROM customer_tokens WHERE token = ? AND expires_at > NOW()", [token], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0) return res.status(401).json({ error: 'Invalid or expired token' });
        const customerId = rows[0].customer_id;
        const sql = `SELECT amount, reference, created_at FROM transactions WHERE customer_id = ? AND type = 'recharge' ORDER BY created_at DESC LIMIT 20`;
        db.query(sql, [customerId], (err, rows) => {
            if (err) return res.status(500).json({ error: err.message });
            res.json(rows);
        });
    });
});

app.get('/customer/devices', (req, res) => {
    const token = req.headers.authorization?.split(' ')[1];
    if (!token) return res.status(401).json({ error: 'No token' });
    db.query("SELECT customer_id FROM customer_tokens WHERE token = ? AND expires_at > NOW()", [token], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0) return res.status(401).json({ error: 'Invalid or expired token' });
        const customerId = rows[0].customer_id;
        const sql = "SELECT id, mac_address, device_name, is_approved, created_at FROM devices WHERE customer_id = ? ORDER BY created_at DESC";
        db.query(sql, [customerId], (err, rows) => {
            if (err) return res.status(500).json({ error: err.message });
            res.json(rows);
        });
    });
});

app.post('/customer/recharge', (req, res) => {
    const token = req.headers.authorization?.split(' ')[1];
    if (!token) return res.status(401).json({ error: 'No token' });
    db.query("SELECT customer_id FROM customer_tokens WHERE token = ? AND expires_at > NOW()", [token], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0) return res.status(401).json({ error: 'Invalid or expired token' });
        const customerId = rows[0].customer_id;
        const { pin_code } = req.body;
        db.query("SELECT * FROM vouchers WHERE pin_code = ? AND is_used = 0 AND deleted_at IS NULL", [pin_code], (err, vouchers) => {
            if (err) return res.status(500).json({ error: err.message });
            if (vouchers.length === 0) return res.status(400).json({ error: 'Invalid or already used voucher' });
            const voucher = vouchers[0];
            db.query("SELECT * FROM customers WHERE id = ?", [customerId], (err, customers) => {
                if (err) return res.status(500).json({ error: err.message });
                if (customers.length === 0) return res.status(404).json({ error: 'Customer not found' });
                const customer = customers[0];
                rechargeWithVoucher(customer, voucher, res);
            });
        });
    });
});

app.post('/customer/logout', (req, res) => {
    const token = req.headers.authorization?.split(' ')[1];
    if (!token) return res.status(401).json({ error: 'No token' });
    db.query("DELETE FROM customer_tokens WHERE token = ?", [token], (err) => {
        if (err) return res.status(500).json({ error: err.message });
        res.json({ message: 'Logged out' });
    });
});

app.listen(port, '0.0.0.0', () => {
    console.log(`Server running on port ${port}`);
});

// Generate MikroTik configuration script for a server
app.get('/admin/servers/:id/script', (req, res) => {
    const { admin_id } = req.query;
    const serverId = req.params.id;
    db.query("SELECT * FROM mikrotik_servers WHERE id = ? AND admin_id = ?", [serverId, admin_id], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0) return res.status(404).json({ error: 'Server not found' });
        const server = rows[0];
        const script = `# MikroTik configuration script for ${server.name}
# Run this in terminal (or upload as .rsc file)

# Add RADIUS servers
/radius add address=${server.ip_address} secret=${server.secret} service=hotspot,ppp,wireless,login
/radius add address=${server.ip_address} secret=${server.secret} service=dhcp

# Enable RADIUS for hotspot and PPP
/ip hotspot profile set [find] use-radius=yes
/ppp profile set [find] use-radius=yes

# Set timeout and retries (adjust if needed)
/radius set [find] timeout=5s
/radius set [find] retries=3

# Allow RADIUS and Winbox from cloud server (optional)
/ip firewall filter add chain=input src-address=${server.ip_address} action=accept comment="Allow RADIUS from cloud"
/ip firewall filter add chain=input protocol=tcp dst-port=8291 src-address=${server.ip_address} action=accept comment="Allow Winbox from cloud"

# If your router is behind NAT, you may need a VPN tunnel
# Example SSTP client (uncomment and adjust)
# /interface sstp-client add connect-to=${server.ip_address} user=${server.username} password=${server.password} disabled=no

# Add a comment to finish
# End of script
`;
        res.setHeader('Content-Type', 'text/plain');
        res.setHeader('Content-Disposition', `attachment; filename="${server.name}_config.rsc"`);
        res.send(script);
    });
});

// Generate MikroTik configuration script for a server
app.get('/admin/servers/:id/script', (req, res) => {
    const { admin_id } = req.query;
    const serverId = req.params.id;
    db.query("SELECT * FROM mikrotik_servers WHERE id = ? AND admin_id = ?", [serverId, admin_id], (err, rows) => {
        if (err) return res.status(500).json({ error: err.message });
        if (rows.length === 0) return res.status(404).json({ error: 'Server not found' });
        const server = rows[0];
        const script = `# MikroTik configuration script for ${server.name}
# Run this in terminal (or upload as .rsc file)

# Add RADIUS servers
/radius add address=${server.ip_address} secret=${server.secret} service=hotspot,ppp,wireless,login
/radius add address=${server.ip_address} secret=${server.secret} service=dhcp

# Enable RADIUS for hotspot and PPP
/ip hotspot profile set [find] use-radius=yes
/ppp profile set [find] use-radius=yes

# Set timeout and retries (adjust if needed)
/radius set [find] timeout=5s
/radius set [find] retries=3

# Allow RADIUS and Winbox from cloud server (optional)
/ip firewall filter add chain=input src-address=${server.ip_address} action=accept comment="Allow RADIUS from cloud"
/ip firewall filter add chain=input protocol=tcp dst-port=8291 src-address=${server.ip_address} action=accept comment="Allow Winbox from cloud"

# If your router is behind NAT, you may need a VPN tunnel
# Example SSTP client (uncomment and adjust)
# /interface sstp-client add connect-to=${server.ip_address} user=${server.username} password=${server.password} disabled=no

# Add a comment to finish
# End of script
`;
        res.setHeader('Content-Type', 'text/plain');
        res.setHeader('Content-Disposition', `attachment; filename="${server.name}_config.rsc"`);
        res.send(script);
    });
});

// Delete a customer (hard delete)
app.delete('/admin/delete-customer', (req, res) => {
    const { admin_id, customer_id } = req.body;
    getAdminRole(admin_id, (err, admin) => {
        if (err) return res.status(500).json({ error: err.message });
        // Optionally check if customer belongs to this admin (if admin is not super_admin)
        db.query("DELETE FROM customers WHERE id = ?", [customer_id], (err, result) => {
            if (err) return res.status(500).json({ error: err.message });
            if (result.affectedRows === 0) return res.status(404).json({ error: 'Customer not found' });
            res.json({ message: 'Customer deleted successfully' });
        });
    });
});
