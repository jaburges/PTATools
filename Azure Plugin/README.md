# Microsoft PTA - Complete Microsoft 365 Integration for WordPress

[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-3.49-orange.svg)](https://github.com/jaburges/PTATools)

**A comprehensive Microsoft 365 integration plugin for WordPress** designed for PTAs, nonprofits, and organizations. Features Azure AD Single Sign-On, automated backups to Azure Blob Storage, email newsletters with visual editor, Outlook calendar embedding, PTA role management, The Events Calendar integration, and more.

> 💡 **Perfect for nonprofits!** Microsoft offers [300 free Business Basic licenses](https://nonprofit.microsoft.com/en-us/getting-started) and [$3,500 annual Azure credits](https://www.microsoft.com/en-us/nonprofits/azure) to qualifying nonprofits.

---

## 🚀 Quick Start

### First-Time Setup (Recommended)
1. **Install the plugin** → Upload to `/wp-content/plugins/` and activate
2. **Complete Setup Wizard** → Follow the guided setup that appears on activation
3. **You're ready!** → The wizard walks you through Azure configuration, module selection, and initial settings

### Manual Setup
1. **Install the plugin** → Upload to `/wp-content/plugins/` and activate
2. **Configure Azure App** → [Create an Azure App Registration](#azure-app-registration)
3. **Enter credentials** → Add Client ID, Secret, and Tenant ID in plugin settings
4. **Enable modules** → Turn on the features you need

📖 **[Full Documentation →](https://github.com/jaburges/AzureSSO/wiki)**

---

## ✨ Features

### 🔐 Authentication & Security
| Module | Description |
|--------|-------------|
| **[SSO Authentication](https://github.com/jaburges/AzureSSO/wiki/SSO-Module)** | Azure AD login with claims mapping, auto user creation, role sync |

### 💾 Data Management
| Module | Description |
|--------|-------------|
| **[Backup to Azure](https://github.com/jaburges/AzureSSO/wiki/Backup-Module)** | Automated backups to Azure Blob Storage with scheduling, granular plugin/theme selection, restore progress tracking, and remote backup sync |

### 📅 Calendar & Events
| Module | Description |
|--------|-------------|
| **[Calendar Embed](https://github.com/jaburges/AzureSSO/wiki/Calendar-Embed-Module)** | Embed Outlook calendars with shortcodes, shared mailbox support |
| **[Calendar Sync (TEC)](https://github.com/jaburges/AzureSSO/wiki/Calendar-Sync-Module)** | Sync Outlook → The Events Calendar with category mapping |
| **[Upcoming Events](https://github.com/jaburges/AzureSSO/wiki/Upcoming-Events-Module)** | Display upcoming TEC events with customizable shortcode |

### 📧 Communication
| Module | Description |
|--------|-------------|
| **[Email via Graph API](https://github.com/jaburges/AzureSSO/wiki/Email-Module)** | Send WordPress emails through Microsoft Graph |
| **[Newsletter](https://github.com/jaburges/AzureSSO/wiki/Newsletter-Module)** | Visual email editor, campaigns, subscriber lists, analytics, spam testing |

### 👥 Organization Management
| Module | Description |
|--------|-------------|
| **[PTA Roles](https://github.com/jaburges/AzureSSO/wiki/PTA-Roles-Module)** | Manage volunteer roles, departments, O365 group sync, org chart with emails, Forminator signup integration |

### 🛒 E-Commerce
| Module | Description |
|--------|-------------|
| **[Classes (WooCommerce)](https://github.com/jaburges/PTATools/wiki/Classes-Module)** | Create class products with TEC integration, variable pricing, commit-to-buy |
| **[Auction](https://github.com/jaburges/PTATools/wiki/Auction-Module)** | Timed bidding, Buy It Now, max bid, confirm-bid modal, instant updates |
| **[Product Fields](https://github.com/jaburges/PTATools/wiki/Product-Fields-Module)** | Custom checkout fields with children profiles, applied by category |
| **[Donations](https://github.com/jaburges/PTATools/wiki/Donations-Module)** | Round-up at checkout, campaigns with goals, `[pta-donate]` shortcode |

### 🙋 Volunteering
| Module | Description |
|--------|-------------|
| **[Volunteer Sign Up](https://github.com/jaburges/PTATools/wiki/Volunteer-Signup-Module)** | SignUpGenius-style sheets with TEC integration, reminders, `[volunteer_signup]` shortcode |

### 📁 Media
| Module | Description |
|--------|-------------|
| **[OneDrive Media](https://github.com/jaburges/AzureSSO/wiki/OneDrive-Module)** | Store media in OneDrive/SharePoint with recursive sync, cloud serving, and Repair Missing Media tool |

---

## 📋 Requirements

### WordPress
- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher

### Microsoft 365
- Microsoft 365 tenant (Business Basic or higher)
- Azure Active Directory (comes with M365)
- Azure App Registration with appropriate permissions

### Optional Plugin Dependencies
| Plugin | Required For |
|--------|--------------|
| [The Events Calendar](https://theeventscalendar.com/) | Calendar Sync, Classes, Upcoming Events |
| [WooCommerce](https://woocommerce.com/) | Classes, Event Tickets, Auction modules |
| [Forminator](https://wpmudev.com/project/forminator/) | PTA Roles signup form integration |
| [Beaver Builder](https://www.wpbeaverbuilder.com/) | Page builder integration |
| [Event Tickets](https://theeventscalendar.com/products/wordpress-event-tickets/) | Event Tickets module |

---

## ⚡ Installation

### From WordPress Admin
1. Download the latest release ZIP
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP file and click **Install Now**
4. Click **Activate**

### Manual Installation
```bash
# Navigate to plugins directory
cd /path/to/wordpress/wp-content/plugins/

# Clone or extract the plugin
git clone https://github.com/jaburges/AzureSSO.git azure-plugin
```

### After Installation
1. Go to **Azure Plugin** in the admin menu
2. Enter your Azure App credentials (see below)
3. Enable desired modules
4. Configure each module's settings

---

## ☁️ Azure App Registration

### Step 1: Access Azure Portal
1. Go to [portal.azure.com](https://portal.azure.com)
2. Sign in with your Microsoft 365 admin account
3. Navigate to **Azure Active Directory → App registrations**

### Step 2: Create New Registration
1. Click **New registration**
2. **Name:** `WordPress Integration` (or your preferred name)
3. **Supported account types:** `Accounts in this organizational directory only`
4. **Redirect URI:** 
   - Platform: `Web`
   - URI: `https://yoursite.com/wp-admin/admin-ajax.php?action=azure_sso_callback`
5. Click **Register**

### Step 3: Configure API Permissions

Add these **Microsoft Graph** permissions based on modules you'll use:

| Permission | Type | Required For |
|------------|------|--------------|
| `User.Read` | Delegated | SSO (basic) |
| `User.Read.All` | Application | SSO (user sync) |
| `Calendars.Read` | Delegated + Application | Calendar Embed, Calendar Sync |
| `Calendars.ReadWrite` | Delegated + Application | Calendar Sync (two-way) |
| `Mail.Send` | Delegated + Application | Email module |
| `Group.Read.All` | Application | PTA Groups sync |
| `Group.ReadWrite.All` | Application | PTA Groups sync (write) |
| `Files.Read.All` | Delegated | OneDrive Media |

> ⚠️ Click **Grant admin consent** after adding permissions!

### Step 4: Create Client Secret
1. Go to **Certificates & secrets**
2. Click **New client secret**
3. Description: `WordPress Plugin`
4. Expiration: 24 months (recommended)
5. Click **Add**
6. **Copy the secret value immediately** (shown only once!)

### Step 5: Note Your Credentials
You'll need these for WordPress:
- **Client ID** (Application ID) - from Overview page
- **Client Secret** - from Step 4
- **Tenant ID** (Directory ID) - from Overview page

---

## 🔧 Module Configuration

### SSO Authentication
```
Location: Azure Plugin → SSO Settings
Credentials: Required
```
- Azure AD login button on wp-login.php
- Automatic user creation with role mapping
- Azure AD claims sync (name, email, department)
- Optional: Force SSO-only authentication
- Exclusion list with domain filtering (block external domains from sync/login)

**Shortcodes:**
```
[azure_sso_login text="Sign in with Microsoft"]
[azure_sso_logout text="Sign out"]
[azure_user_info field="display_name"]
```

### Backup to Azure
```
Location: Azure Plugin → Backup
Credentials: Azure Storage Account + Access Key
```
- Split, component-based backups (database, plugins, themes, media, content)
- Granular plugin/theme selection — choose individual items to include
- Scheduled or manual backups with real-time progress bar
- Post-backup validation verifies all files exist in Azure Storage
- Restore from local backup jobs or directly from Azure Storage (remote sync)
- Selective component restore with progress tracking
- Cross-site restore support — restore backups onto a different WordPress instance
- Chunked uploads and streamed operations to prevent out-of-memory errors
- Retention policy management

📖 **[Full Backup & Restore Guide →](docs/backup-and-restore.md)**

### Calendar Embed
```
Location: Azure Plugin → Calendar Embed
Credentials: Required (OAuth flow)
```
- Embed Outlook calendars via shortcode
- Shared mailbox support
- Multiple view options (month, week, day, list)

**Shortcode:**
```
[azure_calendar email="calendar@org.net" id="CALENDAR_ID" view="month"]
```

### Calendar Sync (TEC Integration)
```
Location: Azure Plugin → Calendar Sync
Credentials: Required (OAuth flow)
Dependencies: The Events Calendar plugin
```
- Sync Outlook calendars to TEC events
- Category mapping per calendar
- Scheduled synchronization
- Recurring event support

### Email via Graph API
```
Location: Azure Plugin → Email
Credentials: Required
```
- Send WordPress emails through Microsoft Graph
- Email logging and tracking
- Works with Contact Form 7, WooCommerce, etc.

### Newsletter
```
Location: Azure Plugin → Newsletter
Credentials: Email service (Mailgun, SendGrid, Amazon SES, or Office 365)
```
- Visual drag-and-drop email editor (GrapesJS)
- Pre-built responsive email templates
- Subscriber list management (WordPress users, custom lists)
- Campaign scheduling and analytics
- Open/click tracking with webhooks
- Spam score testing (SpamAssassin integration)
- Dynamic content blocks (Latest Posts, Upcoming Events, PTA Roles)

### PTA Roles
```
Location: Azure Plugin → PTA Roles
Credentials: Required for O365 Groups sync
```
- Manage departments and roles (VP, Treasurer, Secretary, and custom positions)
- User assignments with audit logging
- O365 Groups synchronization at department and role level
- Interactive org chart with O365 group email links (mailto)
- Forminator integration for role signup forms (opens in modal from org chart)
- Pre-populated forms for logged-in users

**Shortcodes:**
```
[pta-roles-directory columns="3"]
[pta-org-chart department="all"]
[pta-open-positions limit="10"]
```

### Classes (WooCommerce)
```
Location: Azure Plugin → Classes
Credentials: None required
Dependencies: WooCommerce, The Events Calendar
```
- Custom "Class" product type
- Automatic TEC event generation
- Variable pricing with commit-to-buy flow
- Provider taxonomy management

**Shortcodes:**
```
[class_schedule product_id="123"]
[class_pricing product_id="123"]
```

### Upcoming Events
```
Location: Azure Plugin → Upcoming Events
Credentials: None required
Dependencies: The Events Calendar
```
- Display upcoming TEC events
- Customizable layout and filtering
- No credentials needed

**Shortcode:**
```
[up-next columns="2" exclude-categories="Private"]
```

### OneDrive Media
```
Location: Azure Plugin → OneDrive Media
Credentials: Required (OAuth flow)
```
- Store WordPress media in OneDrive/SharePoint
- Automatic upload on media add, optional local copy removal
- Recursive sync into year-based subfolders
- Repair Missing Media tool (re-downloads files after backup restore)
- Sharing link and thumbnail URL generation
- CDN optimization via Microsoft's global network

---

## 🔒 Security Best Practices

1. **HTTPS Required** - Always use SSL in production
2. **Rotate Secrets** - Regenerate client secrets every 6-12 months
3. **Minimal Permissions** - Only grant required API permissions
4. **Admin Access** - Restrict plugin settings to administrators
5. **Regular Backups** - Use the backup module or external solution

---

## 🐛 Troubleshooting

### Common Issues

| Issue | Solution |
|-------|----------|
| "Invalid Taxonomy" error | Ensure the Classes module is enabled |
| Calendar not loading | Check shared mailbox permissions |
| SSO redirect loop | Verify redirect URI matches exactly |
| Backup stuck | Check PHP memory limit (512M+ recommended). Cancel and retry via the admin page. |
| Media missing after restore | Use OneDrive Media → Repair Missing Media to re-download files |
| Sync shows 0 files | Ensure year-based subfolders are being scanned (v3.40+ recurses automatically) |

### Debug Mode
Add to `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

View logs at: **Azure Plugin → System Logs**

---

## 📖 Documentation

- **[Wiki Home](https://github.com/jaburges/AzureSSO/wiki)** - Full documentation
- **[Prerequisites](https://github.com/jaburges/AzureSSO/wiki/Prerequisites)** - What you need before installing
- **[Quick Start](https://github.com/jaburges/AzureSSO/wiki/Quick-Start)** - Get up and running fast
- **[Module Guides](https://github.com/jaburges/AzureSSO/wiki/Modules)** - Detailed module documentation
- **[Advanced Config](https://github.com/jaburges/AzureSSO/wiki/Advanced-Configuration)** - Power user settings
- **[Contributing](https://github.com/jaburges/AzureSSO/wiki/Contributing)** - How to contribute

---

## 🤝 Contributing

Contributions are welcome! Please see our [Contributing Guide](https://github.com/jaburges/AzureSSO/wiki/Contributing).

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/amazing-feature`
3. Commit changes: `git commit -m 'Add amazing feature'`
4. Push to branch: `git push origin feature/amazing-feature`
5. Open a Pull Request

---

## 📜 License

This project is licensed under the GPL v2 or later - see the [LICENSE](LICENSE) file for details.

---

## 🙏 Credits

- **Microsoft Graph API** - Azure and M365 integration
- **The Events Calendar** - Event management foundation
- **WooCommerce** - E-commerce foundation
- **WordPress** - The platform we love

---

**Version 3.49** | [Changelog](CHANGELOG.md) | [Report Issue](https://github.com/jaburges/PTATools/issues)
