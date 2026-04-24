# PTA Tools – WordPress Azure & WooCommerce Integration

A comprehensive WordPress plugin that integrates Microsoft Azure/Microsoft 365 with WordPress and WooCommerce. Single sign-on, calendar sync, email, backups, PTA organizational management, OneDrive media, **Classes**, **Event Tickets**, **Newsletter**, **Auction**, **Product Fields**, **Donations**, and **Volunteer Sign Up** modules—all from one unified plugin (also known as **Microsoft WP**).

**Current (v3.49):** Calendar Sync "Save Settings" now actually saves — removed duplicate AJAX handlers that were racing each other; TEC AJAX handlers now always registered so saves work right after toggling the module on. Also v3.48 Role Editor under PTA Roles for visually editing any role's capabilities.

---

## 📋 **Table of Contents**

1. [Introduction](#introduction)
2. [Recent Updates & Performance](#recent-updates--performance)
3. [Features Overview](#features-overview)
4. [Requirements](#requirements)
5. [Initial Setup & Basic Configuration](#initial-setup--basic-configuration)
6. [Common Configuration](#common-configuration)
7. [Module Configurations](#module-configurations)
   - [SSO (Single Sign-On)](#sso-single-sign-on)
   - [Backup](#backup)
   - [Calendar Embed](#calendar-embed)
   - [Calendar Sync (TEC Integration)](#calendar-sync-tec-integration)
   - [Email](#email)
   - [PTA Roles Management](#pta-roles-management)
   - [OneDrive/SharePoint Media](#onenedrivesharepoint-media)
   - [Classes](#classes)
   - [Newsletter](#newsletter)
   - [Event Tickets](#event-tickets)
   - [Auction](#auction)
   - [Product Fields](#product-fields)
   - [Donations](#donations)
   - [Volunteer Sign Up](#volunteer-sign-up)
8. [Shortcodes Reference](#shortcodes-reference)
9. [Performance & Optimization](#performance--optimization)
10. [Troubleshooting](#troubleshooting)
11. [Contributing](#contributing)
12. [Support & Documentation](#support--documentation)

---

## 🎯 **Introduction**

**PTA Tools** (Microsoft WP) is an all-in-one plugin that brings Microsoft Azure/Microsoft 365 and WooCommerce together. Use it for enterprise authentication, backups, calendar sync, email, PTA roles, OneDrive media, **Classes** (variable pricing, TEC events), **Event Tickets** (seating, QR, Apple Wallet), **Newsletter** (drag-drop editor, tracking), **Auction** (bidding, Buy It Now, winner checkout), **Product Fields** (custom checkout fields with children profiles), **Donations** (round-up at checkout, campaigns, shortcode), and **Volunteer Sign Up** (SignUpGenius-style event sign-up sheets).

### **Why PTA Tools?**

- **Unified Management**: One plugin for Microsoft integrations and PTA/WooCommerce features
- **Enterprise-Grade Security**: OAuth 2.0 with Azure AD
- **Flexible Configuration**: Common or per-module Azure credentials
- **Modular Design**: Enable only the modules you need (SSO, Backup, Calendar, Email, PTA, OneDrive, TEC Sync, Classes, Newsletter, Tickets, Auction, Product Fields, Donations, Volunteer Sign Up)
- **Professional Grade**: Built for reliability and ease of use

---

## 🚀 **Recent Updates & Performance**

### **Version 1.1 - Performance Optimization**

Recent updates have significantly improved plugin performance:

**Performance Improvements:**
- ✅ **45-50% faster page loads** - Eliminated 185 file I/O operations per request
- ✅ **Phase 1:** Removed hot path logging (108 file writes)
- ✅ **Phase 2:** Cleaned component initialization (77 file writes)
- ✅ **Automatic log cleanup** - Old logs deleted after 30 days
- ✅ **Database activity cleanup** - Records cleaned after 90 days
- ✅ **Scheduled maintenance** - Daily WP-Cron jobs for housekeeping

**What Was Optimized:**
1. **Phase 1:** Removed verbose debug logging from plugin initialization and loading
2. **Phase 2:** Cleaned all component init methods, added user-controlled debug mode
3. Implemented intelligent log rotation (20MB limit)
4. Added module-specific debug mode UI (enable only when needed)

**Debug Mode Available:**
- Enable in **Azure Plugin** → **Main Settings** → **Debug Mode**
- Select specific modules to debug (SSO, Calendar, TEC, etc.)
- Zero performance impact when disabled
- Respects WordPress `WP_DEBUG` setting

### **Code Quality Assessment**

A comprehensive code review has been completed:
- **Overall Rating:** 6.5/10
- **Security:** ✅ Strong (623+ proper escaping instances, nonce verification)
- **Architecture:** ✅ Well-structured modular design
- **Performance:** ✅ Recently optimized (was critical issue, now resolved)

**Full review available:** See `review.md` for detailed analysis and roadmap.

---

## ✨ **Features Overview**

### **🔐 SSO (Single Sign-On)**
- Azure AD authentication for WordPress
- Replace or supplement traditional WordPress login
- Automatic user provisioning
- Custom button text and branding
- Forced SSO mode for enhanced security
- Exclusion list with domain-based filtering (block external domains from sync/login)

### **💾 Backup**
- Automated backups to Azure Blob Storage
- Database, files, media, plugins, and themes backup
- Granular plugin/theme selection (choose individual items to back up)
- Scheduled backups with customizable frequency
- Real-time progress bars for both backup and restore operations
- Sync from Azure Storage to list and restore remote backups (useful on new instances)
- Chunked uploads and streamed downloads to handle large sites without OOM
- Email notifications

### **📅 Calendar Embed**
- Embed Microsoft 365/Outlook calendars
- Multiple view options (month, week, day, list)
- Customizable appearance
- Event filtering and display options
- Responsive design

### **🔄 Calendar Sync (TEC Integration)**
- Sync Microsoft 365 calendars to The Events Calendar (TEC)
- Bi-directional sync support
- Category mapping
- Automated scheduled sync
- Per-mapping sync schedules

### **📧 Email**
- Send emails via Microsoft Graph API
- Multiple authentication methods
- Contact forms with spam protection
- Email queue management
- Replace WordPress wp_mail() function

### **🏛️ PTA Roles Management**
- Complete organizational structure management
- Department and role hierarchy with interactive org chart
- Azure AD user provisioning
- Office 365 group sync with department and role-level mappings
- O365 group email display on org chart (mailto links)
- Forminator integration for PTA role signup forms (modal popup from org chart)
- Exec Board, Treasurer, Secretary support alongside VP roles
- Audit trail and reporting

### **📁 OneDrive/SharePoint Media**
- Store WordPress media in OneDrive/SharePoint with automatic upload
- SharePoint document library integration with site/drive browsing
- Recursive sync into year-based subfolders
- Repair Missing Media tool (re-downloads files missing locally after backup restore)
- Sharing link and thumbnail URL generation
- Optional local copy retention or cloud-only storage

### **📚 Classes**
- WooCommerce “Class” product type with TEC event integration
- Variable pricing (min/max attendees, final price)
- Commit-to-buy flow and payment request emails
- Chaperone assignment and season/schedule metadata

### **📧 Newsletter**
- Drag-and-drop newsletter editor
- Lists, campaigns, and queue management
- Open/click tracking and bounce handling
- Send via Microsoft Graph or configured mailer

### **🎫 Event Tickets**
- WooCommerce “Event Ticket” product type
- Visual seating designer and seat selection
- QR code tickets and Apple Wallet
- Event check-in

### **🔨 Auction**
- WooCommerce “Auction” product type
- Bidding end date/time; optional Buy It Now with immediate payment
- Live countdown timer on product page
- Quick bid buttons (+$5, +$10, +$20) and max bid (auto-bid)
- Confirm-bid modal to prevent accidental bids
- Instant bid history updates (no page refresh needed)
- Masked bidder display (e.g. “Ja***”); full bid audit trail in dedicated database table
- Winner order and checkout (Stripe via WooCommerce); “You won” email

### **Product Fields**
- Custom WooCommerce checkout fields (child’s name, allergies, etc.)
- Children Profiles: parents manage multiple child profiles on their account
- Field values auto-populate from saved profiles during checkout
- Group fields and apply by product category
- Saved to user accounts for auto-population on return visits
- Admin management UI under Selling > Product Fields

### **Donations**
- Round-up to nearest dollar toggle at checkout
- Quick-pick donation buttons ($1, $5, $10 or custom)
- Campaign management with fundraising goals and progress tracking
- `[pta-donate]` shortcode for standalone donation forms on any page
- All donations recorded and linked to WooCommerce orders

### **🙋 Volunteer Sign Up**
- SignUpGenius-style volunteer sign-up sheets
- Link sheets to The Events Calendar events (auto-populate date and location)
- Define activities/roles with configurable volunteer spots
- Users sign up from the frontend; guests prompted to log in or register
- Confirmation email on sign-up; 1-day reminder email before the event
- `[volunteer_signup id=“X”]` shortcode for any page
- Admin management UI under Calendar > Volunteer Sign Up

---

## 📋 **Requirements**

### **Minimum Requirements**
- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **MySQL**: 5.6 or higher
- **HTTPS**: Required for Azure authentication
- **cURL Extension**: Required for API communications
- **WooCommerce**: Required for Classes, Event Tickets, Auction, Product Fields, and Donations modules
- **The Events Calendar**: Optional; required for Calendar Sync and Volunteer Sign Up TEC event linking

### **Recommended Requirements**
- **WordPress**: 6.0 or higher
- **PHP**: 8.0 or higher
- **MySQL**: 8.0 or higher
- **Memory**: 256MB or higher
- **Max Execution Time**: 300 seconds (for backups and sync operations)

### **Required PHP Extensions**
- `curl` - For API communications
- `json` - For data processing
- `openssl` - For secure communications
- `zip` - For backup archive creation
- `mysqli` - For database operations

### **Azure Requirements**
- Active Azure subscription (free tier available)
- Azure AD tenant
- App registration in Azure AD
- Appropriate API permissions (varies by module)

---

## 🚀 **Initial Setup & Basic Configuration**

### **Step 1: Install the Plugin**

1. Download the plugin ZIP file
2. In WordPress, go to **Plugins** → **Add New** → **Upload Plugin**
3. Select the `Azure Plugin.zip` file
4. Click **Install Now**
5. After installation, click **Activate Plugin**

### **Step 2: Create Azure App Registration**

Before configuring the plugin, you need to create an App Registration in Azure AD:

1. Go to the [Azure Portal](https://portal.azure.com/)
2. Navigate to **Azure Active Directory** → **App registrations**
3. Click **New registration**
4. Configure your application:
   - **Name**: `WordPress Microsoft Integration` (or your site name)
   - **Supported account types**: 
     - Single tenant (recommended for most organizations)
     - Multi-tenant if needed for guests
   - **Redirect URI**: 
     - Platform: **Web**
     - URL: `https://yoursite.com/wp-admin/admin-ajax.php?action=azure_sso_callback`
     - (Replace `yoursite.com` with your actual domain)

5. Click **Register**

### **Step 3: Get Your Credentials**

After registration, note these important values:

1. **Application (client) ID**: Found on the app overview page
2. **Directory (tenant) ID**: Found on the app overview page
3. **Client Secret**: 
   - Go to **Certificates & secrets**
   - Click **New client secret**
   - Add a description (e.g., "WordPress Plugin")
   - Choose expiration (recommend 24 months)
   - Click **Add**
   - **Copy the Value immediately** (you can't see it again!)

### **Step 4: Configure API Permissions**

In your App Registration, go to **API permissions**:

1. Click **Add a permission**
2. Select **Microsoft Graph**
3. Choose **Delegated permissions** or **Application permissions** based on your needs

**Recommended Starting Permissions:**
- **Delegated permissions**:
  - `User.Read` - Read user profile
  - `Calendars.Read` - Read calendars
  - `Calendars.ReadWrite` - Write to calendars
  - `Mail.Send` - Send emails
  - `Files.Read.All` - Read OneDrive files
  - `Sites.Read.All` - Read SharePoint sites

- **Application permissions** (for PTA module):
  - `User.ReadWrite.All` - Manage users
  - `Group.ReadWrite.All` - Manage groups
  - `Directory.ReadWrite.All` - Update user properties

4. Click **Add permissions**
5. Click **Grant admin consent for [Your Organization]** (requires admin rights)

---

## ⚙️ **Common Configuration**

After installing and activating the plugin, navigate to **Azure Plugin** in your WordPress admin menu.

### **Common Credentials Setup**

The plugin supports two credential modes:

#### **Option 1: Use Common Credentials (Recommended)**

Use one set of Azure credentials for all modules:

1. Navigate to **Azure Plugin** → **Main Settings**
2. Check **"Use common credentials for all modules"**
3. Enter your credentials:
   - **Client ID**: Your Application (client) ID
   - **Client Secret**: Your client secret value
   - **Tenant ID**: Your Directory (tenant) ID (or use `common` for multi-tenant)

4. Click **Save Settings**

**Benefits:**
- Simpler management
- Single app registration needed
- Easier permission management

#### **Option 2: Module-Specific Credentials**

Use different Azure app registrations for each module:

1. Leave **"Use common credentials"** unchecked
2. Configure credentials individually in each module settings page
3. Each module will show its own credential input fields

**Benefits:**
- Granular permission control
- Separate apps for different purposes
- Better security isolation

### **Module Management**

Enable or disable modules based on your needs:

1. Go to **PTA Tools** (Azure Plugin) → **Main Settings**
2. Toggle modules on/off:
   - **SSO** – Single Sign-On authentication
   - **Backup** – Azure Blob Storage backups
   - **Calendar** – Calendar embedding
   - **Email** – Microsoft Graph email
   - **PTA Roles** – Organizational management
   - **TEC Integration** – Sync Outlook calendars to The Events Calendar
   - **OneDrive Media** – OneDrive/SharePoint media
   - **Classes** – Class products with variable pricing and TEC
   - **Newsletter** – Newsletter editor and sending
   - **Event Tickets** – Seating, QR tickets, check-in
   - **Auction** – Auction products with bidding and Buy It Now
   - **Product Fields** – Custom WooCommerce checkout fields
   - **Donations** – Round-up at checkout and campaign donations
   - **Volunteer Sign Up** – SignUpGenius-style event volunteer sheets

3. Click **Configure** next to any enabled module to access its settings

---

## 📦 **Module Configurations**

## 🔐 **SSO (Single Sign-On)**

**Admin Page**: Azure Plugin → SSO

### **Overview**
Enable secure authentication using Microsoft Azure AD. Users can sign in with their Microsoft 365 accounts instead of (or in addition to) WordPress passwords.

### **Configuration Steps**

#### **1. Basic Settings**

- **Require SSO**: Force all users to authenticate via Azure AD (disables WordPress login)
  - ⚠️ Warning: Test SSO thoroughly before enabling this!
- **Auto Create Users**: Automatically create WordPress accounts for new Azure AD users
- **Default Role**: WordPress role assigned to new SSO users (Subscriber, Author, Editor, etc.)

#### **2. Custom Role Configuration**

- **Use Custom Role**: Create a custom WordPress role specifically for Azure AD users
- **Custom Role Name**: Name for the custom role (default: "AzureAD")
  - The role will have basic subscriber capabilities plus `azure_ad_user` capability

#### **3. Login Button Configuration**

- **Show on Login Page**: Display the SSO button on the WordPress login page
- **Button Text**: Customize the text shown on the login button
  - Default: "Sign in with WilderPTSA Email"
  - The Microsoft icon will always be displayed
  - Example: "Sign in with Company Email"

#### **4. User Synchronization**

- **Enable User Sync**: Automatically sync user data from Azure AD
- **Sync Frequency**: How often to sync users (hourly, daily, weekly)
- **Preserve Local Data**: Keep local WordPress user data during sync

#### **5. Exclusion List**

- **Excluded Users**: Manually exclude specific usernames from sync
- **Exclude External Domains**: Check "Do not sync accounts from outside domain" to block all email domains that don't match your organization domain (e.g., block gmail.com, outlook.com, yahoo.com while allowing wilderptsa.net). Applies to both sync and SSO login.

### **Testing SSO**

1. Click **Test SSO Connection** to verify your Azure configuration
2. Open an incognito/private browser window
3. Go to your WordPress login page
4. Click the SSO button
5. Sign in with a Microsoft account
6. Verify you're logged into WordPress

### **SSO Shortcodes**

#### **Login Button**
```
[azure_sso_login]
[azure_sso_login text="Sign in with WilderPTSA Email" redirect="/dashboard"]
```

**Parameters:**
- `text` - Button text (default: "Sign in with WilderPTSA Email")
- `redirect` - URL to redirect to after login
- `class` - CSS class for the button
- `style` - Inline CSS styles

#### **Logout Button**
```
[azure_sso_logout]
[azure_sso_logout text="Sign out" redirect="/"]
```

**Parameters:**
- `text` - Button text (default: "Sign out")
- `redirect` - URL to redirect to after logout
- `class` - CSS class for the button
- `style` - Inline CSS styles

#### **User Information**
```
[azure_user_info]
[azure_user_info field="display_name"]
[azure_user_info field="email"]
```

**Parameters:**
- `field` - Specific field to display:
  - `display_name` - User's display name
  - `email` - User's email address
  - `azure_id` - Azure user ID
  - `last_login` - Last login timestamp
  - `wp_username` - WordPress username
  - `wp_display_name` - WordPress display name
- `logged_out_text` - Message shown when user is not logged in
- `format` - Output format (`html` or `json`)

---

## 💾 **Backup**

**Admin Page**: Azure Plugin → Backup

### **Overview**
Automated backups of your WordPress site to Azure Blob Storage. Securely store database, files, media, plugins, and themes in the cloud.

### **Prerequisites**
- Azure Storage Account
- Azure Blob Storage container
- Storage account access key

### **Azure Storage Setup**

1. In Azure Portal, create a **Storage Account**
2. Create a **Container** (e.g., "wordpress-backups")
3. Note your **Storage Account Name** and **Access Key**

### **Configuration Steps**

#### **1. Storage Credentials**

If not using common credentials:
- **Storage Account Name**: Your Azure storage account name
- **Storage Account Key**: Access key from Azure Portal
- **Container Name**: Name of your blob container

#### **2. Backup Settings**

- **Backup Types**: Select what to backup:
  - ☑ Database
  - ☑ WordPress Content (`wp-content` folder)
  - ☑ Media Files (`wp-content/uploads`)
  - ☑ Plugins — expandable with individual plugin checkboxes
  - ☑ Themes — expandable with individual theme checkboxes
- Use **"select individually"** to expand plugins or themes and choose specific items

- **Scheduled Backups**:
  - Enable automated backups
  - Frequency: hourly, daily, weekly, monthly

- **Backup Retention**:
  - Number of backups to keep
  - Older backups are automatically deleted

- **Email Notifications**:
  - Send email when backup completes
  - Send email on backup failures
  - Notification email address

#### **3. Manual Backup**

Click **Start Manual Backup** to run an immediate backup. A real-time progress bar tracks each phase (database, content, media, plugins, themes, archive creation, Azure upload).

#### **4. Restore from Backup**

**From Recent Backup Jobs (local database):**
1. Find the backup in **Recent Backup Jobs**
2. Click **Restore** next to the backup
3. A progress bar tracks the restore operation in real-time
4. ⚠️ **Warning**: This will overwrite existing data!

**From Azure Storage (remote / new instance):**
1. Click **Sync from Azure** to list all backups stored in Azure Blob Storage
2. The **Azure Storage Backups** table shows blob name, size, date, and whether a local job record exists
3. Click **Restore** on any remote backup to download and restore it directly
4. Progress is tracked in real-time during download, extraction, and restoration

### **No Shortcodes Available**

---

## 📅 **Calendar Embed**

**Admin Page**: Azure Plugin → Calendar

### **Overview**
Embed Microsoft 365 or Outlook calendars directly into your WordPress pages and posts. Display events in multiple views with customizable styling.

### **Configuration Steps**

#### **1. Authentication**

- **Your M365 Account**: Your Microsoft 365 email address
- **Shared Mailbox Email** (optional): Email of a shared mailbox to access
- Click **Authenticate Calendar** to sign in with Microsoft
- Grant permissions when prompted

#### **2. Calendar Selection**

After authentication:
1. Click **Refresh Calendars** to load your available calendars
2. Select which calendars to enable for embedding
3. Set timezone for each calendar
4. Click **Save Calendar Settings**

### **Calendar Embed Shortcodes**

#### **Full Calendar Display**
```
[azure_calendar id="calendar_id"]
[azure_calendar id="AQMkADAwATMwMAItZjFiZS00" view="month" height="600px"]
```

**Parameters:**
- `id` - **Required**. Calendar ID from Calendar admin page
- `view` - Display view: `month`, `week`, `day`, `list` (default: `month`)
- `height` - Calendar height (default: `600px`)
- `width` - Calendar width (default: `100%`)
- `theme` - Color theme: `default`, `dark`
- `timezone` - Timezone for display
- `max_events` - Maximum events to display (default: 100)
- `start_date` - Start date filter (ISO format)
- `end_date` - End date filter (ISO format)
- `show_toolbar` - Show calendar navigation toolbar (default: `true`)
- `show_weekends` - Display weekends (default: `true`)
- `first_day` - First day of week: `0` (Sunday) or `1` (Monday)
- `time_format` - Time format: `12h` or `24h`

#### **Events List**
```
[azure_calendar_events id="calendar_id"]
[azure_calendar_events id="calendar_id" limit="10" format="list" upcoming_only="true"]
```

**Parameters:**
- `id` - **Required**. Calendar ID
- `limit` - Number of events to display (default: 10)
- `format` - Display format: `list`, `grid`, `compact` (default: `list`)
- `show_dates` - Show event dates (default: `true`)
- `show_times` - Show event times (default: `true`)
- `show_location` - Show event location (default: `true`)
- `show_description` - Show event description (default: `false`)
- `date_format` - PHP date format (default: `M j, Y`)
- `time_format` - PHP time format (default: `g:i A`)
- `upcoming_only` - Show only future events (default: `true`)
- `class` - CSS class for styling

#### **Single Event**
```
[azure_calendar_event id="calendar_id" event_id="event_id"]
```

**Parameters:**
- `id` - **Required**. Calendar ID
- `event_id` - **Required**. Specific event ID
- `show_attendees` - Show event attendees (default: `false`)
- `show_description` - Show event description (default: `true`)
- `class` - CSS class for styling

---

## 🔄 **Calendar Sync (TEC Integration)**

**Admin Page**: Azure Plugin → Calendar → TEC Sync Tab

### **Overview**
Synchronize Microsoft 365 calendars with The Events Calendar (TEC) plugin. Automatically import Outlook events as WordPress events with category mapping and scheduled sync.

### **Prerequisites**
- The Events Calendar plugin must be installed and activated
- Calendar authentication (same as Calendar Embed module)

### **Configuration Steps**

#### **1. Authentication**

Use the same authentication as Calendar Embed:
- **Your M365 Account**: Your Microsoft 365 email address
- **Shared Mailbox Email** (optional): Shared mailbox to sync from
- Click **Authenticate Calendar**

#### **2. Create Calendar Mappings**

1. Click **Add Calendar Mapping**
2. In the modal:
   - **Outlook Calendar**: Select the source Microsoft calendar
   - **TEC Category**: Choose or create a TEC category
   - **Schedule Settings**:
     - Enable scheduled sync
     - Sync frequency (every 15min, 30min, hourly, daily)
     - Lookback days (how far in the past to sync)
     - Lookahead days (how far in the future to sync)
3. Click **Save Mapping**

#### **3. Sync Options**

- **Manual Sync**: Click **Sync Now** to immediately sync all mappings
- **Scheduled Sync**: Enable per-mapping automatic synchronization
- **Delete Mapping**: Remove calendar mappings you no longer need

### **How It Works**

1. Plugin fetches events from your Outlook calendar
2. Events are created/updated in The Events Calendar
3. Events are assigned to the mapped TEC category
4. Sync runs on the configured schedule
5. Duplicate events are prevented by checking event IDs

### **No Additional Shortcodes**

TEC Integration uses The Events Calendar's built-in shortcodes and display features. Refer to TEC documentation for displaying synced events.

---

## 📧 **Email**

**Admin Page**: Azure Plugin → Email

### **Overview**
Send emails through Microsoft Graph API instead of traditional SMTP. Includes contact forms, email queue management, and WordPress wp_mail() replacement.

### **Configuration Steps**

#### **1. Authentication Methods**

Choose your authentication approach:

- **User Authentication**: Send emails on behalf of specific users
  - Enter user email address
  - Authenticate with Microsoft
  - Grant Mail.Send permissions

- **Application Authentication**: Send emails as the application
  - Requires application-level permissions
  - No per-user authentication needed

#### **2. Email Settings**

- **From Name**: Default sender name for emails
- **From Email**: Default sender email address
- **Reply-To Email**: Email for replies
- **Replace wp_mail()**: Use Microsoft Graph for all WordPress emails

#### **3. Contact Form Configuration**

- **Enable Contact Forms**: Allow use of contact form shortcode
- **Default Recipient**: Email address to receive form submissions
- **Spam Protection**: Enable built-in spam filtering
- **Rate Limiting**: Limit form submissions per IP

#### **4. Email Queue**

- **Enable Queue**: Process emails asynchronously
- **Queue Processing**: Frequency of queue processing
- **Retry Failed**: Automatically retry failed emails
- **Max Retries**: Number of retry attempts

### **Email Shortcodes**

#### **Contact Form**
```
[azure_contact_form]
[azure_contact_form to="admin@site.com" subject="Contact Form Submission"]
```

**Parameters:**
- `to` - Recipient email address
- `subject` - Email subject line
- `show_phone` - Show phone number field (default: `false`)
- `show_company` - Show company field (default: `false`)
- `required_fields` - Comma-separated list: `name,email,phone,message`
- `success_message` - Message shown after successful submission
- `button_text` - Submit button text (default: "Send Message")
- `class` - CSS class for form styling

**Example with all options:**
```
[azure_contact_form 
    to="sales@company.com" 
    subject="Sales Inquiry" 
    show_phone="true" 
    show_company="true"
    required_fields="name,email,phone,message"
    success_message="Thanks! We'll contact you soon."
    button_text="Get in Touch"]
```

#### **Email Status** (Admin Only)
```
[azure_email_status]
[azure_email_status show_queue_count="true"]
```

**Parameters:**
- `show_queue_count` - Display pending email count (default: `true`)
- `show_failed_count` - Display failed email count (default: `true`)
- `show_success_rate` - Display success rate percentage (default: `true`)

#### **Email Queue** (Admin Only)
```
[azure_email_queue]
[azure_email_queue limit="20" status="pending"]
```

**Parameters:**
- `limit` - Number of emails to display (default: 10)
- `status` - Filter by status: `pending`, `sent`, `failed`, `all` (default: `all`)
- `show_details` - Show email content preview (default: `false`)

---

## 🏛️ **PTA Roles Management**

**Admin Page**: Azure Plugin → PTA Roles

### **Overview**
Complete organizational management system for PTAs and nonprofits. Manage departments, roles, and assignments with automatic Azure AD provisioning and Office 365 group synchronization.

### **Configuration Steps**

#### **1. Enable PTA Module**

1. Go to **Azure Plugin** → **Main Settings**
2. Enable **PTA Roles** module
3. Ensure you have these Azure permissions:
   - `User.ReadWrite.All`
   - `Group.ReadWrite.All`
   - `Directory.ReadWrite.All`

#### **2. Department Management**

1. Go to **PTA Roles** → **Departments**
2. Default departments are pre-configured:
   - Exec Board
   - Communications
   - Enrichment
   - Events
   - Volunteers
   - Ways and Means
3. Assign Vice Presidents (VPs) to each department
4. VPs become Azure AD managers for their department members

#### **3. Role Management**

1. Go to **PTA Roles** → **Roles**
2. 58+ pre-configured roles available
3. Each role has:
   - Name and description
   - Department assignment
   - Maximum occupancy
   - Current assignment count

#### **4. User Assignments**

1. Go to **PTA Roles** → **Assign Users**
2. Select a user
3. Assign to one or more roles
4. Set primary role (determines Azure AD manager)
5. Changes automatically sync to Azure AD

#### **5. Office 365 Groups**

1. Go to **PTA Roles** → **O365 Groups**
2. Click **Sync O365 Groups** to import groups from tenant
3. Create mappings:
   - Map individual roles to groups (via Edit Role modal)
   - Map departments to groups (via Edit Department modal)
4. Group memberships automatically sync based on role assignments
5. O365 group emails appear on the org chart as `mailto:` links (e.g., `president@wilderptsa.net`)

#### **6. Forminator Integration (Signup Forms)**

1. Go to **PTA Roles** → **Forminator Customization**
2. Select a Forminator form to use as the PTA role signup form
3. Map form fields to role name, department, first name, last name, email
4. When visitors click a role on the org chart, a modal opens with the form pre-populated
5. Logged-in users have their name and email pre-filled automatically

#### **6. Monitoring**

- **Sync Queue**: Monitor background jobs for user provisioning
- **Audit Logs**: Complete history of all organizational changes
- **Dashboard**: Overview of departments, roles, and assignments

### **How It Works**

1. **User Assignment**: Admin assigns WordPress user to PTA role
2. **Azure AD Provisioning**: If user doesn't have Azure AD account:
   - Account is automatically created
   - Email: `firstname+lastInitial@wilderptsa.net`
   - Office 365 Business Basic license assigned
   - Temporary password generated
3. **Manager Hierarchy**: Primary role determines Azure AD manager
   - Department VPs become managers
   - Hierarchy syncs to Azure AD
4. **Group Membership**: User is added to mapped Office 365 groups
5. **Job Title Sync**: Role assignments become Azure AD job titles

### **PTA Shortcodes**

#### **Roles Directory**
```
[pta-roles-directory]
[pta-roles-directory department="communications" columns="3" layout="team-cards"]
```

**Parameters:**
- `department` - Filter by department name or slug
- `description` - Show role descriptions (default: `false`)
- `status` - Filter by status: `all`, `open`, `filled`, `partial` (default: `all`)
- `columns` - Number of columns: 1-5 (default: 3)
- `show_count` - Show assignment count (default: `true`)
- `show_vp` - Show department VP (default: `false`)
- `layout` - Display layout: `grid`, `list`, `cards`, `team-cards` (default: `grid`)
- `show_avatars` - Show user avatars in team-cards layout (default: `true`)
- `show_contact` - Show contact links in team-cards layout (default: `true`)
- `avatar_size` - Avatar size in pixels (default: 80)

#### **Department Roles**
```
[pta-department-roles department="communications"]
[pta-department-roles department="events" show_vp="true" show_description="true"]
```

**Parameters:**
- `department` - **Required**. Department name or slug
- `show_vp` - Show department VP (default: `true`)
- `show_description` - Show role descriptions (default: `false`)
- `layout` - Display layout: `list`, `grid` (default: `list`)

#### **Org Chart**
```
[pta-org-chart]
[pta-org-chart department="all" interactive="true" height="500px"]
```

**Parameters:**
- `department` - Department to display or `all` (default: `all`)
- `interactive` - Enable interactive features (default: `false`)
- `height` - Chart height (default: `400px`)

#### **Role Card**
```
[pta-role-card role="president"]
[pta-role-card role="communications-vp" show_contact="true"]
```

**Parameters:**
- `role` - **Required**. Role name or slug
- `show_contact` - Show contact information (default: `false`)
- `show_description` - Show role description (default: `true`)
- `show_assignments` - Show assigned users (default: `true`)

#### **Department VP**
```
[pta-department-vp department="communications"]
[pta-department-vp department="events" show_email="true"]
```

**Parameters:**
- `department` - **Required**. Department name or slug
- `show_contact` - Show contact information (default: `false`)
- `show_email` - Show email address (default: `false`)

#### **Open Positions**
```
[pta-open-positions]
[pta-open-positions department="volunteers" limit="5"]
```

**Parameters:**
- `department` - Filter by department or `all` (default: `all`)
- `limit` - Maximum positions to show (default: -1 for all)
- `show_department` - Show department names (default: `true`)
- `show_description` - Show role descriptions (default: `false`)

#### **User Roles**
```
[pta-user-roles]
[pta-user-roles user_id="123" show_department="true"]
```

**Parameters:**
- `user_id` - User ID to display roles for (default: current user)
- `show_department` - Show department names (default: `true`)
- `show_description` - Show role descriptions (default: `false`)

---

## 📁 **OneDrive/SharePoint Media**

**Admin Page**: Azure Plugin → OneDrive Media

### **Overview**
Store WordPress media files in OneDrive or SharePoint with automatic upload, cloud-first serving, and full Media Library integration. Files uploaded through WordPress are sent to OneDrive/SharePoint and optionally removed locally to save server space.

### **Configuration Steps**

#### **1. Authentication**

- Enter your M365 email address
- Click **Authorize OneDrive Access**
- Grant permissions:
  - `Files.Read.All`
  - `Files.ReadWrite.All`
  - `Sites.Read.All`

#### **2. Storage Type Selection**

Choose your storage location:

**Option A: OneDrive**
1. Select **OneDrive**
2. Click **Browse Folders**
3. Select your base folder
4. Click **Select Folder**

**Option B: SharePoint**
1. Select **SharePoint**
2. Enter SharePoint site URL
3. Click **Browse Sites** to search for your site
4. Click **Browse Drives** to select document library
5. Click **Browse Folders** to select base folder

#### **3. Media Organization**

- **Base Folder**: Root folder for media files
- **Year-Based Folders**: Automatically organize media by year (e.g., `WordPress Media/2025/`, `WordPress Media/2026/`)
- **Create Year Folders**: Click to generate folder structure

#### **4. Sync & Repair**

- **Sync from OneDrive Now**: Import new files from OneDrive into the Media Library. Recursively walks all subfolders (including year-based folders) to find files not yet mapped.
- **Repair Missing Media**: Re-download files that have OneDrive mappings but are missing locally. Essential after restoring a backup on a new server where the physical media files weren't included. Also refreshes stale sharing/thumbnail URLs.
- **Auto-Sync**: Enable scheduled sync (hourly, twice daily, or daily) to automatically import new files.

#### **5. Public Access & CDN**

- **Sharing Links**: Anonymous or organization-only access links
- **CDN Optimization**: Leverage Microsoft's global CDN for faster delivery
- **Local Copies**: Optionally keep local copies or serve directly from OneDrive

### **No Shortcodes Available**

Files are accessed through the WordPress media library interface. OneDrive/SharePoint URLs are transparently served via `wp_get_attachment_url` filters.

---

## 📚 **Classes**

**Admin Page**: Azure Plugin → Classes

### **Overview**

WooCommerce “Class” product type for courses/workshops with The Events Calendar integration, variable pricing based on enrollment, and a commit-to-buy flow. Admins set schedule, venue, chaperone, and pricing; customers commit first and pay when the final price is set.

### **Key Features**

- **Product type**: “Class” in WooCommerce product type selector
- **Schedule**: Start date, recurrence, occurrences, start time, duration
- **Variable pricing**: Min/max attendees, price at min/max, final price when finalized
- **Commitment flow**: $0 checkout to reserve; payment request email when final price is set
- **Chaperone**: Assign and invite by email
- **TEC**: Optional link to TEC events for calendar display

### **No Shortcodes Listed Here**

Class products are sold via standard WooCommerce product pages and cart/checkout.

---

## 📧 **Newsletter**

**Admin Page**: Azure Plugin → Newsletter

### **Overview**

Create and send newsletters with a drag-and-drop editor, manage lists and campaigns, track opens/clicks, and handle bounces. Can use the plugin’s email module (e.g. Microsoft Graph) or your configured mailer.

### **Key Features**

- **Editor**: Drag-and-drop content blocks
- **Lists**: Subscriber lists and membership
- **Campaigns**: Create and send campaigns from templates
- **Queue**: Queue management and sending
- **Tracking**: Open and click tracking; bounce handling and stats

### **No Shortcodes Reference in This Section**

Newsletter signup/display shortcodes (if any) are configured in the Newsletter module settings.

---

## 🎫 **Event Tickets**

**Admin Page**: Azure Plugin → Event Tickets

### **Overview**

WooCommerce “Event Ticket” product type with visual seating designer, seat selection on the frontend, QR code tickets, Apple Wallet support, and event check-in.

### **Key Features**

- **Product type**: “Event Ticket” in WooCommerce
- **Seating**: Designer for venue layouts; customers pick seats
- **Tickets**: QR codes and Apple Wallet passes
- **Check-in**: Dedicated check-in page/tool for events
- **TEC**: Link to The Events Calendar events and venues

### **No Shortcodes Listed Here**

Tickets are sold via WooCommerce product pages; seating UI is shown on the single product page.

---

## 🔨 **Auction**

**Admin Page**: Azure Plugin → Auction

### **Overview**

WooCommerce “Auction” product type with timed bidding, optional Buy It Now, max bid (proxy/auto-bid), and winner checkout. Requires WooCommerce; payment runs through WooCommerce checkout (e.g. Stripe via WooCommerce Stripe Gateway).

### **Configuration Steps**

#### **1. Enable the Module**

1. Go to **Azure Plugin** → **Main Settings**
2. Enable **Auction**
3. Click **Configure** to open the Auction dashboard

#### **2. Create an Auction Product**

1. Go to **Products** → **Add New** (or edit a product)
2. Set **Product type** to **Auction**
3. Open the **Auction** tab and set:
   - **Starting Bid**: the opening bid amount (saved as Regular Price)
   - **Bidding End Date** and **Bidding End Time**
   - **Buy It Now**: checkbox and price (optional)
   - **Require immediate payment**: when checked, Buy It Now sends the customer to checkout immediately

4. Publish the product

#### **3. How Bidding Works**

- **Frontend**: Single product page shows current/starting bid, live countdown timer, bid input with compact quick buttons (+$5, +$10, +$20), and optional “Set max bid”. A confirm-bid modal prevents accidental bids. Bid history updates instantly without page refresh.
- **Login**: Users must be logged in to bid; others see “Register/Login to bid”.
- **Max bid**: If set, the system auto-bids up to that amount in increments (e.g. $5) when outbid.
- **Audit**: All bids, times, and IP addresses are stored in the `wp_azure_auction_bids` database table.

#### **4. When the Auction Ends**

- When the bidding end time has passed (checked on product load or cron), the auction is marked ended.
- The winner is the user with the highest bid at end time.
- A WooCommerce order is created for the winning amount and the winner is emailed a “You won” message with a link to checkout to pay (Stripe or other gateways as configured).

#### **5. Buy It Now**

- If Buy It Now is enabled and the auction has not ended, a “Buy It Now” button is shown.
- On click, an order is created at the Buy It Now price and the customer is redirected to checkout.
- The auction is marked sold so the normal “winner” flow does not run.

### **Requirements**

- **WooCommerce** must be active
- **Stripe**: Use WooCommerce Stripe Gateway for Stripe; no separate Stripe SDK in the plugin
- **Email**: “You won” emails use `wp_mail` (or the plugin’s email module if you route transactional mail through it)

### **No Shortcodes**

Auction products are displayed and bid on via the standard WooCommerce single product page.

---

## 📝 **Product Fields**

**Admin Page**: PTA Tools > Selling > Product Fields

### **Overview**

Custom WooCommerce checkout fields that are saved to user accounts and auto-populated on return visits. Create field groups (e.g. "Child Information") and apply them to specific product categories.

### **Key Features**

- **Field Groups**: Group related fields together (e.g. "Student Info", "Dietary Needs")
- **Category Mapping**: Apply field groups to specific WooCommerce product categories
- **User Meta Storage**: Field values saved to user accounts for auto-population
- **Checkout Integration**: Fields appear on checkout for applicable products
- **Order Meta**: Field values saved to order line items

### **Configuration**

1. Go to **PTA Tools** > **Selling** > **Product Fields**
2. Create a **Field Group** (e.g. "Student Information")
3. Add fields to the group (text, select, checkbox, etc.)
4. Assign the group to product categories
5. Fields automatically appear at checkout for matching products

### **No Shortcodes**

Product Fields are managed through the admin UI and appear automatically on WooCommerce checkout pages.

---

## 💝 **Donations**

**Admin Page**: PTA Tools > Selling > Donations

### **Overview**

Accept donations at checkout with round-up and custom amount options. Create fundraising campaigns with goals and progress tracking. Place standalone donation forms on any page with the `[pta-donate]` shortcode.

### **Configuration Steps**

#### **1. Enable the Module**

1. Go to **PTA Tools** > **Main Settings**
2. Enable **Donations** under the Selling card
3. Navigate to **PTA Tools** > **Selling** > **Donations**

#### **2. Create a Campaign**

1. Click **New Campaign**
2. Enter name, description, and optional fundraising goal
3. Save the campaign

#### **3. Configure Settings**

- **Enable Round-Up**: Show "Round up to nearest dollar" toggle at checkout
- **Enable Custom Amount**: Show quick-pick donation buttons at checkout
- **Quick Amounts**: Comma-separated dollar amounts (e.g. `1,5,10,25`)
- **Default Campaign**: Which campaign receives donations

### **Checkout Widget**

When enabled, a donation widget appears before the Place Order button:
- **Round-up toggle**: Rounds the cart total to the nearest dollar
- **Quick-pick buttons**: Pre-set donation amounts ($1, $5, $10)
- **Custom input**: Enter any amount
- Donations are added as WooCommerce cart fees
- Recorded and linked to campaigns after order completion

### **Donations Shortcode**

```
[pta-donate]
[pta-donate campaign_id="1" amounts="5,10,25,50" button_text="Support Us"]
```

**Parameters:**
- `campaign_id` - Campaign to donate to (default: the default campaign)
- `amounts` - Comma-separated dollar amounts (default: `5,10,25,50`)
- `show_custom` - Show custom amount input: `yes` or `no` (default: `yes`)
- `button_text` - Submit button text (default: "Donate Now")

Displays a standalone donation form with:
- Campaign name, description, and progress bar (if goal is set)
- Amount selection buttons
- Optional custom amount input
- Adds donation to WooCommerce cart as a fee

---

## 🙋 **Volunteer Sign Up**

**Admin Page**: PTA Tools > Calendar > Volunteer Sign Up

### **Overview**

A SignUpGenius-style volunteer coordination system. Create sign-up sheets for events, define activities with volunteer spots, and let users sign up from the frontend. Optionally link sheets to The Events Calendar events for automatic date/location population.

### **Configuration Steps**

#### **1. Enable the Module**

1. Go to **PTA Tools** > **Main Settings**
2. Enable **Volunteer Sign Up** under the Calendar module card
3. Navigate to **PTA Tools** > **Calendar** > **Volunteer Sign Up** tab

#### **2. Create a Sign-Up Sheet**

1. Click **New Sign-Up Sheet**
2. Enter a title, optional description
3. Optionally link to a TEC event (auto-populates date and location)
4. Set event date and location manually if not using TEC
5. Set status to **Open** or **Closed**

#### **3. Add Activities / Roles**

1. In the sheet editor modal, add activities under **Activities / Roles**
2. Each activity has:
   - **Name**: e.g. "Concessions 4PM"
   - **Description**: optional details
   - **Spots Needed**: number of volunteers required
3. Click **Save Sheet**

#### **4. Display on the Frontend**

Use the shortcode on any page or post:

```
[volunteer_signup id="1"]
```

The frontend displays:
- Sheet title, description, and event meta (date, location)
- Activity cards showing available spots and current volunteers
- Logged-in users can check activities and click **Save** to sign up
- Users can withdraw from activities they've signed up for
- Guests see a login/register prompt

#### **5. Emails and Reminders**

- **Confirmation email**: Sent immediately after sign-up with event name, activities, date, and location
- **Reminder email**: Sent automatically 1 day before the event date via a daily scheduled job

### **Volunteer Sign Up Shortcode**

```
[volunteer_signup id="1"]
```

**Parameters:**
- `id` - **Required**. The sign-up sheet ID (shown in the admin table shortcode column)

---

## 📚 **Shortcodes Reference**

### **Quick Reference Table**

| Module | Shortcode | Purpose |
|--------|-----------|---------|
| **SSO** | `[azure_sso_login]` | Login button |
| | `[azure_sso_logout]` | Logout button |
| | `[azure_user_info]` | Display user information |
| **Calendar** | `[azure_calendar]` | Full calendar display |
| | `[azure_calendar_events]` | Events list |
| | `[azure_calendar_event]` | Single event |
| **Email** | `[azure_contact_form]` | Contact form |
| | `[azure_email_status]` | Email status (admin) |
| | `[azure_email_queue]` | Email queue (admin) |
| **PTA** | `[pta-roles-directory]` | All roles display |
| | `[pta-department-roles]` | Department-specific roles |
| | `[pta-org-chart]` | Organization chart |
| | `[pta-role-card]` | Single role details |
| | `[pta-department-vp]` | Department VP info |
| | `[pta-open-positions]` | Open positions list |
| | `[pta-user-roles]` | User's role assignments |
| **Classes** | — | Product-based; use WooCommerce product/cart pages |
| **Newsletter** | — | Configure in Newsletter module (lists/campaigns) |
| **Event Tickets** | — | Product-based; seating on single product page |
| **Auction** | — | Product-based; bid UI on single product page |
| **Product Fields** | — | Auto-displayed on WooCommerce checkout |
| **Donations** | `[pta-donate]` | Standalone donation form |
| **Volunteer Sign Up** | `[volunteer_signup id="X"]` | Event volunteer sign-up sheet |

### **Shortcode Examples**

See each module's section above for detailed parameters and examples.

---

## ⚡ **Performance & Optimization**

### **Current Performance Metrics**

After recent optimizations (Version 1.1):
- **Admin Page Load:** 350-600ms (was 800-1200ms) - **45-50% faster**
- **Plugin Initialization:** <100ms (was 200-400ms) - **50%+ faster**
- **File I/O Operations:** 0 per request (was 185) - **100% reduction**
- **Log File Growth:** <50KB/day (was 5MB/day) - **99% reduction**

### **Automatic Maintenance**

The plugin includes automatic maintenance features:

1. **Log Rotation**
   - Logs automatically rotate at 20MB
   - Last 5 backups kept
   - Older backups deleted after 30 days

2. **Database Cleanup**
   - Activity logs cleaned after 90 days
   - Runs daily via WP-Cron
   - No manual intervention needed

3. **Debug Mode**
   - Enable only when troubleshooting
   - Module-specific debugging available
   - Automatically disabled in production

### **Performance Best Practices**

**For Optimal Performance:**
1. ✅ Disable debug mode in production
2. ✅ Use common credentials (simpler, less overhead)
3. ✅ Enable object caching (Redis/Memcached if available)
4. ✅ Limit calendar sync lookback days
5. ✅ Use reasonable backup retention periods

**PHP Requirements for Best Performance:**
- PHP 8.0 or higher recommended
- Memory limit: 256MB minimum
- Max execution time: 300 seconds (for backups/sync)
- Enable OPcache if available

### **Monitoring Performance**

**Check Plugin Health:**
1. Go to **Azure Plugin** → **Logs**
2. Monitor log file size (should stay under 20MB)
3. Check for repeated error messages
4. Review sync queue status (PTA module)

**WordPress Debug Mode:**
```php
// Enable for troubleshooting (wp-config.php)
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

**Plugin Debug Mode:**
1. Go to **Azure Plugin** → **Main Settings**
2. Enable **Debug Mode**
3. Select specific modules to debug
4. Check `wp-content/plugins/Azure Plugin/logs.md`

### **Performance Optimization Roadmap**

**Already Completed:** ✅
- **Phase 1:** Critical logging cleanup (hot path - 108 writes eliminated)
- **Phase 2:** Component initialization cleanup (77 writes eliminated)
- Scheduled maintenance system
- User-controlled debug mode UI
- Module-specific debugging (8 modules)

**Planned Improvements:**
- Database query optimization (SELECT * replacement)
- Settings caching implementation
- OAuth token caching consolidation
- CSS refactoring (remove !important overuse)

See `review.md` for complete optimization roadmap and priorities.

---

## 🔧 **Troubleshooting**

### **Common Issues**

#### **Authentication Failed**
- Verify Client ID, Client Secret, and Tenant ID are correct
- Check that redirect URI matches in Azure App Registration
- Ensure admin consent is granted for API permissions
- Clear browser cookies and try again

#### **Calendar Not Loading**
- Verify calendar authentication is complete
- Check that Calendar.Read permission is granted
- Clear calendar cache in plugin settings
- Check browser console for JavaScript errors

#### **Backup Failures**
- Verify Azure Storage account credentials
- Check that container exists and is accessible
- Ensure WordPress has write permissions
- Review backup logs for specific errors
- Increase PHP max execution time if needed

#### **Email Not Sending**
- Verify Mail.Send permission is granted
- Check email authentication status
   - Review email queue for failed messages
   - Check WordPress debug logs
- Verify sender email is from your domain

#### **PTA Sync Issues**
- Verify User.ReadWrite.All permission
   - Check sync queue for failed jobs
   - Ensure Office 365 licenses are available
   - Review PTA sync engine logs
- Verify department VPs are assigned

#### **OneDrive Connection Failed**
- Check Files.Read.All permission
- Verify authentication is complete
- Test connection button
- Check site URL format for SharePoint

#### **Auction Bidding or Buy It Now Not Working**
- Ensure **WooCommerce** is installed and active
- Ensure **Auction** module is enabled in Main Settings
- Users must be logged in to bid; show “Register/Login to bid” if not
- For Stripe: install WooCommerce Stripe Gateway; payment runs through checkout
- Set a **Starting Bid** in the product's Auction tab (stored as Regular Price)

#### **Volunteer Sign Up Sheet Not Displaying**
- Ensure **Volunteer Sign Up** is enabled under Calendar on Main Settings
- Verify the shortcode uses a valid sheet ID: `[volunteer_signup id="1"]`
- The Events Calendar plugin is optional but required for TEC event linking

#### **Donations Campaign Save Failed (400)**
- Ensure **Donations** module is enabled in Main Settings under Selling
- If the module was recently added, reload the main settings page after enabling

### **Debug Mode**

The plugin has two debug modes:

**WordPress Debug (for all plugins):**
```php
// Add to wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

**Plugin-Specific Debug:**
1. Go to **Azure Plugin** → **Main Settings**
2. Check **Enable Debug Mode**
3. Select modules to debug (or leave empty for all)
4. Click **Save Changes**

**Where to Find Logs:**
- WordPress errors: `wp-content/debug.log`
- Plugin logs: `wp-content/plugins/Azure Plugin/logs.md`
- PHP errors: Check server error logs

**⚠️ Important:** 
- Debug mode impacts performance - use only for troubleshooting
- Logs auto-rotate at 20MB
- Old logs auto-delete after 30 days

### **Testing Connections**

Each module has a **Test Connection** button to verify:
- Azure credentials are valid
- Permissions are granted
- API endpoints are accessible
- Authentication is working

### **Performance Issues**

**If experiencing slow performance:**

1. **Disable Debug Mode** (if enabled)
   - Go to **Azure Plugin** → **Main Settings**
   - Uncheck **Debug Mode**

2. **Check Log File Size**
   - View `wp-content/plugins/Azure Plugin/logs.md`
   - Should be under 20MB (auto-rotates at 20MB)
   - If oversized, delete or move the file

3. **Clear Plugin Caches**
   - Use cache clearing options in plugin settings

4. **Optimize Sync Settings**
   - Reduce calendar sync frequency
   - Limit event lookback days
   - Adjust PTA sync intervals

5. **Increase PHP Resources**
   - Memory limit: 256MB minimum
   - Max execution time: 300 seconds
   - Enable OPcache if available

6. **Enable WordPress Object Caching**
   - Use Redis or Memcached for better performance
   - Caches settings and API responses

**Recent Performance Fixes (v1.1):**
- ✅ **Phase 1:** Eliminated 108 file operations from hot paths
- ✅ **Phase 2:** Eliminated 77 file operations from component init
- ✅ **Total:** 185 → 0 file operations per request (100% reduction)
- ✅ Implemented automatic log rotation
- ✅ Added user-controlled debug mode
- ✅ Added scheduled maintenance
- ✅ **Result: 45-50% faster page loads**

---

## 🤝 **Contributing**

Contributions are welcome! Whether you're fixing bugs, adding features, or improving documentation, we appreciate your help.

### **Getting Started**

1. **Fork** the repository on GitHub
2. **Clone** your fork locally:
   ```bash
   git clone https://github.com/YOUR-USERNAME/PTATools.git
   ```
3. Create a **feature branch**:
   ```bash
   git checkout -b feature/your-feature-name
   ```
4. Make your changes and **test thoroughly** on a WordPress test site
5. **Commit** with a clear message:
   ```bash
   git commit -m "Add: brief description of your change"
   ```
6. **Push** to your fork and open a **Pull Request**

### **Creating Issues**

We use GitHub Issues to track bugs and feature requests. Please use one of the provided issue templates:

- **Bug Report**: For reporting broken functionality or errors
- **Feature Request**: For suggesting new features or enhancements

When creating an issue, please include:
- **WordPress version** and **PHP version**
- **Plugin version** (found on the PTA Tools main settings page)
- **Module affected** (SSO, Backup, Calendar, Email, PTA Roles, OneDrive, Classes, Newsletter, Tickets, Auction, Product Fields, Donations, Volunteer Sign Up, or System)
- **Steps to reproduce** (for bugs) or **use case** (for features)

[Create a new issue](https://github.com/jaburges/PTATools/issues/new/choose)

### **Development Guidelines**

- Follow existing code patterns and module architecture
- Keep files under 500 lines; refactor if needed
- Use WordPress coding standards for PHP
- Add proper nonce verification and capability checks for all AJAX handlers
- Use `Azure_Logger` for debug logging (not `error_log` in production paths)
- Test with WooCommerce enabled and disabled if your changes touch selling modules
- Database tables must be created via `dbDelta()` in `class-database.php`

### **Module Architecture**

Each module follows a consistent pattern:
- **Class file**: `includes/class-{module}-module.php` (singleton with `get_instance()`)
- **Admin page**: `admin/{module}-page.php` (included as a tab or standalone page)
- **Frontend CSS**: `css/{module}-frontend.css` (conditionally enqueued)
- **Initialization**: Wired in `azure-plugin.php` with an `init_{module}_components()` method
- **Toggle**: Added to `valid_modules` array in `class-admin.php`

---

## 📞 **Support & Documentation**

### **Getting Help**

1. **Check this README**: Comprehensive documentation for all features
2. **Admin Help Text**: Each settings page has helpful descriptions
3. **Test Connections**: Use built-in testing tools
4. **Review Logs**: Check plugin logs and WordPress debug logs
5. **WordPress Forums**: Post questions with plugin tag
6. **GitHub Issues**: Report bugs and feature requests

### **Plugin Documentation**

- **Review & Roadmap**: See `review.md` for detailed code review and optimization roadmap
- **Performance Guide**: See Performance & Optimization section above
- **Logging Strategy**: Automatic rotation and cleanup implemented

### **Useful Links**

- **Azure Portal**: https://portal.azure.com/
- **Microsoft Graph Explorer**: https://developer.microsoft.com/graph/graph-explorer
- **Azure AD Documentation**: https://docs.microsoft.com/azure/active-directory/
- **Microsoft Graph API**: https://docs.microsoft.com/graph/

### **Best Practices**

**Configuration:**
- **Start with Common Credentials**: Easier to manage initially
- **Test in Staging**: Try features on a test site first
- **Review Permissions**: Grant only needed Azure permissions

**Operations:**
- **Regular Backups**: Enable automated backups immediately
- **Monitor Sync Queues**: Check PTA sync status regularly
- **Keep Updated**: Update plugin when new versions release

**Performance:**
- **Disable Debug Mode**: In production environments
- **Monitor Log Sizes**: Check if logs.md is growing too large
- **Optimize Sync Intervals**: Use reasonable frequencies
- **Enable Caching**: Use WordPress object caching if available

**Troubleshooting:**
- **Enable Debug Mode**: Only when diagnosing issues
- **Module-Specific Debug**: Select specific modules to reduce noise
- **Review Logs**: Check both WordPress and plugin logs
- **Test Connections**: Use built-in connection test buttons

---

## 📄 **License**

This plugin is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

---

## 🙏 **Credits**

This plugin integrates and enhances functionality from multiple Microsoft services:

- Microsoft Azure Active Directory
- Microsoft Graph API
- Azure Blob Storage
- Microsoft 365 / Office 365
- OneDrive for Business
- SharePoint Online
- Microsoft Outlook Calendar
- Exchange Online

**Built with ❤️ for WordPress and the Microsoft community.**

---

## 📊 **Version History**

### **Version 3.49** (Current — April 2026)
- **Calendar Sync — Save Settings actually saves**: Removed duplicate `wp_ajax_azure_save_tec_calendar_email` / `azure_tec_calendar_authorize` / `azure_tec_calendar_check_auth` handlers from `class-admin.php`. Both classes were registering the same action name, so the first-registered one would `wp_die()` before the dedicated TEC handler could ever run. The dedicated `Azure_TEC_Integration_Ajax` class is now the single source of truth for these handlers.
- **TEC AJAX always registered**: `Azure_TEC_Integration_Ajax` is now instantiated unconditionally in `init()`, not gated behind `enable_tec_integration`. This means Save Settings / Authenticate / Check Auth work immediately after toggling the module on (previously you'd have to reload for handlers to register).

### **Version 3.48** (April 2026)
- **Role Editor** (new): New page under PTA Roles → Role Editor. Pick any WP role (including Azure-AD-synced roles like `azuread`/"Azure AD User") from a dropdown and visually toggle capabilities grouped by functional area (Core, Users, Posts, Pages, Media, Comments, Themes, Plugins, Tools, WooCommerce, TEC, PTA/Azure, Other). Includes friendly labels for core caps, dangerous-cap badges, filter/search, group "All" toggles, "Copy from..." another role, and sticky save toolbar
- **Role Editor safety**: Administrator role is locked from edits (prevents lockout); `azure_ad_user` marker preserved on synced roles; all changes logged to `Azure_Database`
- **Calendar Sync — toggle persistence**: The enable/disable toggle on the Calendar Sync page now uses the universal `module-toggle` handler and actually saves to the database. Previously it used an unhooked class and did nothing on click
- **Calendar Sync — Authenticate button**: Fixed undefined `$tec_calendar_email` variable that prevented the "Authenticate Calendar" button from ever appearing. Now shows correctly once both M365 and mailbox emails are saved
- **Calendar Sync — nonce/field mismatches**: Aligned `azureTecAdmin` nonce with AJAX handlers (`azure_plugin_nonce`); AJAX handlers now accept both `user_email` and legacy `email` POST keys
- **Admin JS**: Sub-module toggles no longer flip the parent card's enabled/disabled visual; toggle-status label now updates after a successful save on module-specific pages

### **Version 3.47** (April 2026)
- **Stability**: All `catch (Exception)` upgraded to `catch (\Throwable)` across core, logger, and module init — prevents uncaught `Error`/`ParseError` from crashing the site
- **Graceful degradation**: Plugin no longer self-deactivates on missing files; shows admin notice instead. WooCommerce-optional guards prevent fatals when WC is absent
- **Login shortcode**: `[user-account-dropdown]` now cache-safe — renders placeholder, then fetches logged-in state via AJAX to work with full-page caching (W3TC/Redis/AFD)
- **Donations**: Widget now appears on cart page and WooCommerce Blocks checkout (auto-relocates via JS); context-aware refresh for classic, Blocks, and cart
- **Admin UI**: Sub-module toggles (Calendar Sync, Volunteer, Auction, Classes, Product Fields, Donations) converted from checkboxes to mini toggle switches on main settings page

### **Version 3.46** (March 2026)
- **UI**: Fixed dashicon alignment in admin tab bars, page headings, and action row buttons — removed conflicting CSS properties that fought with flex layout
- **Donations**: Shortcode amount buttons now display in a compact single row with explicit text color, fixing invisible text on themes that override button styles

### **Version 3.45** (March 2026)
- **Volunteer Sign Up**: New module — SignUpGenius-style sign-up sheets linked to TEC events, with activities/spots, frontend sign-up, confirmation and reminder emails, `[volunteer_signup]` shortcode
- **Auction**: Frontend overhaul — live countdown timer, confirm-bid modal, instant bid history updates (no page refresh), compact quick-bid button layout, dedicated Starting Bid admin field
- **Product Fields**: Children Profiles — parents manage multiple child profiles on their account; auto-populated during checkout
- **Donations**: Fixed admin AJAX handlers always registering so campaign management works regardless of frontend toggle

### **Version 3.43** (March 2026)
- **Donations**: New module with round-up at checkout, custom amounts, campaigns with goals/progress, and `[pta-donate]` shortcode
- **Product Fields**: Custom WooCommerce checkout fields saved to user accounts, applied by product category
- **UI Overhaul**: Consolidated admin into tabbed pages — Calendar (Embed/Sync/Upcoming), System (Logs/Schedules/Critical), Emails (Logs/Settings), Selling (Auction/Classes/Product Fields/Donations)
- **System**: Scheduled Jobs dashboard for all plugin cron jobs with Run Now and monitoring
- **OneDrive Media**: Replaced sync with one-time Import from OneDrive preserving folder structure; batched import with progress
- **SSO**: Adjusted sync frequency to hourly (configurable)

### **Version 3.40** (March 2026)
- ✅ **Backup**: Granular plugin/theme selection with expandable checkboxes
- ✅ **Backup**: Restore progress bar with real-time status tracking
- ✅ **Backup**: Sync from Azure Storage — list and restore remote backups on new instances
- ✅ **Backup**: Chunked Azure uploads, streamed downloads, streamed SQL restore (OOM prevention)
- ✅ **Backup**: Async background processing, improved stale job detection, partial failure reporting
- ✅ **OneDrive Media**: Recursive sync into year/month subfolders
- ✅ **OneDrive Media**: Repair Missing Media tool for post-restore recovery
- ✅ **PTA Roles**: Forminator integration — signup form in modal from org chart
- ✅ **PTA Roles**: O365 group email display on org chart as mailto links
- ✅ **PTA Roles**: Role-level O365 group mappings; Treasurer/Secretary support
- ✅ **SSO**: External domain exclusion for sync and login
- ✅ **Dashboard**: Plugin dependency badges link to WordPress plugin install page

### **Version 3.35**
- ✅ **Auction module**: WooCommerce Auction product type, bidding, Buy It Now, winner flow
- ✅ Calendar: Manual Sync Now button; sync history for TEC Integration

### **Version 1.1**
- ✅ Major performance optimization (45-50% faster)
- ✅ User-controlled debug mode, log rotation, scheduled maintenance

### **Version 1.0**
- Initial release with core modules

---

## 🎯 **Development Status**

**Current Focus:** UI polish, Volunteer Sign Up, Auction improvements, Children Profiles
**Code Quality:** See `review.md` for details
**Test Coverage:** Manual testing (automated tests planned)
**Documentation:** README, GitHub Wiki, and inline admin help

**Modules:** SSO, Backup, Calendar (Embed + Sync + Upcoming + Volunteer Sign Up), Emails (Logs + Settings), PTA Roles, OneDrive Media, Classes, Newsletter, Event Tickets, Auction, Product Fields, Donations, System (Logs + Schedules + Critical)

**Planned Improvements:**
- Database query optimization, settings caching, CSS refactoring
- OAuth token handling consolidation
- Automated testing

See `review.md` for full roadmap and priorities.

---

**Version**: 3.46  
**Author**: Jamie Burgess  
**Last Updated**: March 2026  
**Plugin URI**: https://github.com/jaburges/PTATools

**Ready to get started?** Follow the [Initial Setup](#initial-setup--basic-configuration) guide above!

**Need help?** Check [Troubleshooting](#troubleshooting) or review the [Performance & Optimization](#performance--optimization) section.