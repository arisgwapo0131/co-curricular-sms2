# BCP Co-Curricular Management System — Account Credentials

> [!CAUTION]
> **DEVELOPMENT & TESTING CREDENTIALS ONLY**
> These credentials apply solely to seeded development and testing environments.
> In staging or production environments, supply strong unique passwords via environment variables:
> `ADMIN_DEFAULT_PASSWORD`, `SSC_DEFAULT_PASSWORD`, `ADVISER_DEFAULT_PASSWORD`, and `STUDENT_DEFAULT_PASSWORD`.
> All accounts must have their credentials rotated upon initial provisioning.

This document contains the complete list of system accounts, default passwords, and assigned organizations/programs for testing and system administration.

---

## 🔑 Quick Password Reference

| Role Category | Password |
|---|---|
| **Super Admin Account** | `Bcp@SuperAdmin2026!` |
| **System Admin Account** | `Bcp@Admin2026!` |
| **Staff (OSA) Account** | `Bcp@Staff2026!` |
| **SSC Officer Account** | `Bcp@SSC2026!` |
| **Faculty Adviser Accounts** | `Bcp@Adviser2026!` |
| **Student Accounts** | `Bcp@Test2026!` |

---

## 🛡️ Central Administrative & Operational Accounts

| Role | Name | Username | Email | Password | Scope / Primary Authority | MFA / OTP |
|---|---|---|---|---|---|:---:|
| **Super Admin** | Super Admin | `super.admin` | `pangilinanarisguillersaret@gmail.com` | `Bcp@SuperAdmin2026!` | Root System Authority, Complete RBAC Matrix, Security & Audit | Auto Email OTP |
| **System Admin** | System Admin | `scc.admin` | `pangilinanarisguillersaret@gmail.com` | `Bcp@Admin2026!` | Master Data, User Accounts, Academic Catalogs, Final Event Clearance | Auto Email OTP |
| **Staff (OSA)** | OSA Staff | `osa.staff` | `pangilinanarisguillersaret@gmail.com` | `Bcp@Staff2026!` | Office of Student Affairs Operations, Attendance Scanning & QR, Roster Oversight | Auto Email OTP |
| **SSC Officer** | SSC Officer | `ssc.officer` | `pangilinanarisguillersaret@gmail.com` | `Bcp@SSC2026!` | Supreme Student Council Governance, Stage 2 Event Endorsement, Org Charters | Auto Email OTP |

> *Note:* When signing in with administrative accounts (`super.admin`, `scc.admin`, `osa.staff`, `ssc.officer`) or primary student (`bsit.student`), the OTP (6-digit verification code) is dispatched directly to `pangilinanarisguillersaret@gmail.com` via SMTP.

---

## 🎓 Student Accounts (16 Academic Programs)

All student accounts have the password: **`Bcp@Test2026!`**

| # | Student Number | Program / Course | Student Name | Username | Email | Year & Section |
|---|---|---|---|---|---|---|
| 1 | `2024-10001` | **BSIT** (Information Technology) | Juan Santos | `bsit.student` | `pangilinanarisguillersaret@gmail.com` | 2nd Year (IT-2A) |
| 2 | `2024-10002` | **BSHM** (Hospitality Management) | Maria Cruz | `bshm.student` | `bshm@student.bcp.edu.ph` | 1st Year (HM-1B) |
| 3 | `2024-10003` | **BSAIS** (Accounting Info Systems) | Jose Reyes | `bsais.student` | `bsais@student.bcp.edu.ph` | 3rd Year (AIS-3A) |
| 4 | `2024-10004` | **BSTM** (Tourism Management) | Ana Dela Cruz | `bstm.student` | `bstm@student.bcp.edu.ph` | 2nd Year (TM-2C) |
| 5 | `2024-10005` | **BSOA** (Office Administration) | Carlos Garcia | `bsoa.student` | `bsoa@student.bcp.edu.ph` | 1st Year (OA-1A) |
| 6 | `2024-10006` | **BSE** (Entrepreneurship) | Liza Ramos | `bse.student` | `bse@student.bcp.edu.ph` | 3rd Year (ENT-3B) |
| 7 | `2024-10007` | **BSBA** (Business Administration) | Ramon Villanueva | `bsba.student` | `bsba@student.bcp.edu.ph` | 2nd Year (BA-2A) |
| 8 | `2024-10008` | **BSIS** (Information Science) | Patricia Aquino | `bsis.student` | `bsis@student.bcp.edu.ph` | 1st Year (IS-1A) |
| 9 | `2024-10009` | **BSCpE** (Computer Engineering) | Mark Bautista | `bscpe.student` | `bscpe@student.bcp.edu.ph` | 3rd Year (CPE-3A) |
| 10 | `2024-10010` | **BSPsych** (Psychology) | Jenny Navarro | `bspsych.student` | `bspsych@student.bcp.edu.ph` | 2nd Year (PSY-2B) |
| 11 | `2024-10011` | **BSCrim** (Criminology) | Rico Fernandez | `bscrim.student` | `bscrim@student.bcp.edu.ph` | 4th Year (CRIM-4A) |
| 12 | `2024-10012` | **BSPE** (Physical Education) | Sheila Santos | `bspe.student` | `bspe@student.bcp.edu.ph` | 2nd Year (PE-2A) |
| 13 | `2024-10013` | **TLE** (Tech & Livelihood Ed) | Angelo Torres | `tle.student` | `tle@student.bcp.edu.ph` | 1st Year (TLE-1B) |
| 14 | `2024-10014` | **BSElEd** (Elementary Education) | Claire Mendoza | `bseled.student` | `bseled@student.bcp.edu.ph` | 3rd Year (ELED-3A) |
| 15 | `2024-10015` | **BSSecEd** (Secondary Education) | Danilo Pascual | `bsseed.student` | `bsseed@student.bcp.edu.ph` | 2nd Year (SEED-2C) |
| 16 | `2024-10016` | **BSLIS** (Library & Info Science) | Rowena Espinosa | `bslis.student` | `bslis@student.bcp.edu.ph` | 3rd Year (LIS-3A) |

---

## 👨‍🏫 Faculty Adviser Accounts (40 Campus Organizations)

All faculty adviser accounts have the password: **`Bcp@Adviser2026!`**

| # | Org Code | Organization Name | Adviser Name | Username | Email |
|---|---|---|---|---|---|
| 1 | **CSSEC** | Computer Science Student Exec Council | Prof. Alex Reyes | `cssec.adviser` | `cssec@adviser.bcp.edu.ph` |
| 2 | **ACADS** | Association of Computer Eng Driven Students | Prof. Mark Velo | `acads.adviser` | `acads@adviser.bcp.edu.ph` |
| 3 | **ACES** | Association of Computer Engineering Students | Prof. Elena Ramos | `aces.adviser` | `aces@adviser.bcp.edu.ph` |
| 4 | **AISS** | Accounting Info System Society | Prof. Clara Tan | `aiss.adviser` | `aiss@adviser.bcp.edu.ph` |
| 5 | **BLISS** | Bestlink Library & Info Science Society | Prof. Robert Cruz | `bliss.adviser` | `bliss@adviser.bcp.edu.ph` |
| 6 | **BRAVE** | Values Education & Accountability Org | Prof. Diana Gomez | `brave.adviser` | `brave@adviser.bcp.edu.ph` |
| 7 | **CJSU** | Criminal Justice Student Unit | Prof. Jose Mercado | `cjsu.adviser` | `cjsu@adviser.bcp.edu.ph` |
| 8 | **EYO** | Entrepreyouth Organization | Prof. Lisa Santos | `eyo.adviser` | `eyo@adviser.bcp.edu.ph` |
| 9 | **G.A.L.A.W** | Athletes & Leaders Wellness Assoc | Prof. Manuel Cruz | `galaw.adviser` | `galaw@adviser.bcp.edu.ph` |
| 10 | **GEMs** | Guild of English Majors | Prof. Anna Reyes | `gems.adviser` | `gems@adviser.bcp.edu.ph` |
| 11 | **GOLD** | Guild of Officers to Lead Development | Prof. Karen Lim | `gold.adviser` | `gold@adviser.bcp.edu.ph` |
| 12 | **JFINEX** | Junior Financial Executives | Prof. Manuel Cruz | `jfinex.adviser` | `jfinex@adviser.bcp.edu.ph` |
| 13 | **HRS** | Human Resources Society | Prof. Alex Reyes | `hrs.adviser` | `hrs@adviser.bcp.edu.ph` |
| 14 | **J.M.A** | Junior Marketing Association | Prof. Sarah Mercado | `jma.adviser` | `jma@adviser.bcp.edu.ph` |
| 15 | **LAKAS** | Liga ng Aktibong Kabataan sa Araling Panlipunan | Prof. Clara Tan | `lakas.adviser` | `lakas@adviser.bcp.edu.ph` |
| 16 | **L.A.P.I.S** | Leadership Assoc Program & Services | Prof. Diana Gomez | `lapis.adviser` | `lapis@adviser.bcp.edu.ph` |
| 17 | **LIBRO** | Lucid of Bright & Righteous Officers | Prof. Robert Cruz | `libro.adviser` | `libro@adviser.bcp.edu.ph` |
| 18 | **OMEGA** | Org for Mathematics in Engineering | Prof. Jose Mercado | `omega.adviser` | `omega@adviser.bcp.edu.ph` |
| 19 | **PsychSoc** | Psychology Society | Prof. Lisa Santos | `psychsoc.adviser` | `psychsoc@adviser.bcp.edu.ph` |
| 20 | **RSD** | Regnum Scientiae Discipulus | Prof. Anna Reyes | `rsd.adviser` | `rsd@adviser.bcp.edu.ph` |
| 21 | **SIGMA** | Guild for Mathematics Majors | Prof. Karen Lim | `sigma.adviser` | `sigma@adviser.bcp.edu.ph` |
| 22 | **TECHs** | Tech, Exploratory & Hospitality Skills | Prof. Alex Reyes | `techs.adviser` | `techs@adviser.bcp.edu.ph` |
| 23 | **TTS** | Tourism Student Society | Prof. Sarah Mercado | `tts.adviser` | `tts@adviser.bcp.edu.ph` |
| 24 | **WIKA** | Wikang Filipino sa Akademya | Prof. Clara Tan | `wika.adviser` | `wika@adviser.bcp.edu.ph` |
| 25 | **ACAC** | Association of Cultural Art Club | Prof. Sarah Mercado | `acac.adviser` | `acac@adviser.bcp.edu.ph` |
| 26 | **CESC** | Computer Engineering Sports Club | Prof. Mark Velo | `cesc.adviser` | `cesc@adviser.bcp.edu.ph` |
| 27 | **EBCPCT** | Elite BCP Chess Team | Prof. Mark Velo | `ebcpct.adviser` | `ebcpct@adviser.bcp.edu.ph` |
| 28 | **RCYC-BCP** | Red Cross Youth Council - BCP | Dr. Elena Cruz | `rcyc.adviser` | `rcyc@adviser.bcp.edu.ph` |
| 29 | **SMC** | Shuttle Master Club | Prof. Mark Velo | `smc.adviser` | `smc@adviser.bcp.edu.ph` |
| 30 | **ALL STAR** | All Star Talent Group | Prof. Sarah Mercado | `allstar.adviser` | `allstar@adviser.bcp.edu.ph` |
| 31 | **B-FORCE** | B-Force Hip-Hop Crew | Prof. Sarah Mercado | `bforce.adviser` | `bforce@adviser.bcp.edu.ph` |
| 32 | **CREATIVE** | Creative Arts Group | Prof. Sarah Mercado | `creative.adviser` | `creative@adviser.bcp.edu.ph` |
| 33 | **CDC** | Criminology Dance Company | Prof. Sarah Mercado | `cdc.adviser` | `cdc@adviser.bcp.edu.ph` |
| 34 | **DLC** | Drum and Lyre Corporation | Prof. Sarah Mercado | `dlc.adviser` | `dlc@adviser.bcp.edu.ph` |
| 35 | **IKATLONG** | Ikatlong Lahi Royalties | Prof. Sarah Mercado | `ikatlong.adviser` | `ikatlong@adviser.bcp.edu.ph` |
| 36 | **IMAGE** | Image Alchemy Media Crew | Prof. Sarah Mercado | `image.adviser` | `image@adviser.bcp.edu.ph` |
| 37 | **S.I.K.A.T** | Theater & Acting Group | Prof. Sarah Mercado | `sikat.adviser` | `sikat@adviser.bcp.edu.ph` |
| 38 | **UV** | Unlimited Voice Choir | Prof. Sarah Mercado | `uv.adviser` | `uv@adviser.bcp.edu.ph` |
| 39 | **PEER** | Peer Counselor Support Group | Dr. Elena Cruz | `peer.adviser` | `peer@adviser.bcp.edu.ph` |
| 40 | **NEWSLINK** | School Publications | Dr. Elena Cruz | `newslink.adviser` | `newslink@adviser.bcp.edu.ph` |
| 41 | **ITS** | Information Technology Society | Prof. Danilo Santos | `bsit.adviser` | `bsit@adviser.bcp.edu.ph` |

