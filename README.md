# Email Bridge

**Email Bridge** is a Nextcloud application designed to transmit and schedule high-value email content directly from your cloud files.

It allows you to manage automated email sequences, track their progress, and control content delivery — all within your Nextcloud instance.

---

## 🚀 Features

- Transmit files and content through email sequences  
- Schedule messages with full control over timing  
- Synchronize automatically with your Nextcloud environment  
- Clean interface to visualize and control all message flows  

---

## 🧩 Installation

1. Copy the app into your Nextcloud `apps/` directory:
   ```bash
   git clone https://github.com/Vince2956/emailbridge.git apps/emailbridge

2. Enable it from the Nextcloud web interface or with the command:
   occ app:enable emailbridge

---

## 🧠 Usage

Once enabled, you’ll find Email Bridge in the Nextcloud sidebar.
Create your first sequence
Link files or messages to be sent
Schedule and track your email flows in real time
The app works best when fully synchronized with its environment — each instance is independent and managed by its own administrator.

---

## 🛠️ Development

If you wish to contribute or modify the app:
git clone https://github.com/Vince2956/emailbridge.git
cd emailbridge
npm install
npm run dev

---

## 📄 License

This project is licensed under the **GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later)**.

Copyright © 2025 Vincent Scaviner

You are free to use, modify, and distribute this software under the terms of the AGPL-3.0 license.  
See the [LICENSE](LICENSE) file for the full text.