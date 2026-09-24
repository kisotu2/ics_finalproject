from pathlib import Path
from PIL import Image, ImageDraw, ImageFont
from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn

ROOT = Path(__file__).parent
OUT = ROOT / 'output' / 'System_Analysis_and_Design.docx'
FIG = ROOT / 'output' / 'system_analysis_figures'
FIG.mkdir(parents=True, exist_ok=True)

BLUE = '1F4D78'; MID = '2E74B5'; PALE = 'E8EEF5'; GREY = 'F2F4F7'; INK = '202020'; GREEN = 'D9EAD3'; ORANGE = 'FCE5CD'

def font(size, bold=False):
    candidates = ['/System/Library/Fonts/Supplemental/Arial.ttf', '/Library/Fonts/Arial.ttf']
    if bold: candidates = ['/System/Library/Fonts/Supplemental/Arial Bold.ttf', '/Library/Fonts/Arial Bold.ttf'] + candidates
    for p in candidates:
        if Path(p).exists(): return ImageFont.truetype(p, size)
    return ImageFont.load_default()

F10, F11, F12, F14, F16, F18 = [font(n) for n in (20, 22, 24, 28, 32, 36)]
FB12, FB14, FB16 = [font(n, True) for n in (24, 28, 32)]

def canvas(name, width=1500, height=950):
    im = Image.new('RGB', (width, height), 'white')
    return im, ImageDraw.Draw(im), FIG / name

def box(d, xy, title, lines=(), fill=PALE, radius=18, title_font=FB14, text_font=F11):
    x1,y1,x2,y2 = xy
    d.rounded_rectangle(xy, radius=radius, fill='#'+fill, outline='#'+BLUE, width=3)
    d.text((x1+16,y1+13), title, font=title_font, fill='#'+BLUE)
    y=y1+56
    for line in lines:
        d.text((x1+16,y), line, font=text_font, fill='#'+INK)
        y += 30

def arrow(d, start, end, label=None, dashed=False):
    d.line([start,end], fill='#'+BLUE, width=3)
    x,y=end; sx,sy=start
    import math
    ang=math.atan2(y-sy,x-sx)
    a1=ang+2.65; a2=ang-2.65
    for a in (a1,a2): d.line([(x,y),(x+18*math.cos(a),y+18*math.sin(a))],fill='#'+BLUE,width=3)
    if label:
        mx,my=(sx+x)//2,(sy+y)//2
        d.rectangle((mx-6,my-17,mx+6+len(label)*12,my+14),fill='white')
        d.text((mx,my-14),label,font=F10,fill='#'+INK)

def actor(d, x, y, label):
    d.ellipse((x-16,y,x+16,y+32), outline='#'+BLUE, width=3)
    d.line((x,y+32,x,y+87),fill='#'+BLUE,width=3); d.line((x-32,y+52,x+32,y+52),fill='#'+BLUE,width=3)
    d.line((x,y+87,x-28,y+122),fill='#'+BLUE,width=3); d.line((x,y+87,x+28,y+122),fill='#'+BLUE,width=3)
    d.text((x-50,y+132),label,font=FB12,fill='#'+BLUE)

def use_case():
    im,d,p=canvas('use_case.png',1500,980)
    d.rounded_rectangle((260,50,1260,920),radius=24,outline='#'+MID,width=4)
    d.text((615,65),'Smart Asset Management System',font=FB16,fill='#'+BLUE)
    actor(d,110,220,'Administrator'); actor(d,1385,230,'Employee'); actor(d,110,650,'Super Admin')
    cases=[(465,170,'Manage users & roles'),(820,170,'Register / update assets'),(465,360,'Assign, return & retire assets'),(820,360,'Record maintenance & view risk'),(465,550,'Manage software licences'),(820,550,'Manage approved areas & alerts'),(465,740,'Generate reports / audit trail'),(820,740,'View assigned asset & check in location')]
    for x,y,t in cases:
        d.ellipse((x-150,y-40,x+150,y+40),fill='#'+PALE,outline='#'+BLUE,width=3)
        bb=d.textbbox((0,0),t,font=F11); d.text((x-(bb[2]-bb[0])/2,y-13),t,font=F11,fill='#'+INK)
    for pt in [(315,230),(315,400),(315,590),(315,780)]: arrow(d,(165,285 if pt[1]<500 else 715),pt)
    for pt in [(1110,780),(1110,590)]: arrow(d,(1330,295),pt)
    for pt in [(315,230),(315,400),(315,780)]: arrow(d,(165,715),pt)
    im.save(p); return p

def sequence():
    im,d,p=canvas('sequence.png',1500,1010)
    names=['Employee','Browser','Check-in API','Database','Alert service']
    xs=[130,410,700,990,1280]
    for x,n in zip(xs,names):
        box(d,(x-90,40,x+90,100),n,(),GREY,title_font=FB12)
        d.line((x,100,x,940),fill='#888888',width=2)
    steps=[(150,410,170,'1. Select assigned asset and request check-in'),(410,700,300,'2. POST consent, location and CSRF token'),(700,990,430,'3. Verify session, CSRF and asset ownership'),(700,990,550,'4. Store authorised location record'),(700,990,670,'5. Read active approved areas'),(990,700,750,'6. Return whether location is inside an area'),(700,1280,840,'7. If outside, create alert and send optional email'),(700,410,920,'8. Return check-in result to user')]
    for sx,ex,y,l in steps: arrow(d,(sx,y),(ex,y),l)
    im.save(p); return p

def erd():
    im,d,p=canvas('erd.png',1600,1120)
    ents=[
      (40,60,270,245,'USERS',['PK id','email, role, status','department']),
      (390,60,650,245,'LAPTOPS',['PK id','FK assigned_to','asset_tag, status']),
      (760,60,1030,245,'MAINTENANCE_RECORDS',['PK id','FK laptop_id','FK reported_by']),
      (1130,60,1480,245,'DEVICE_USAGE_DAILY',['PK id','FK laptop_id','usage_date']),
      (40,410,340,595,'SOFTWARES',['PK id','vendor, version','total_licenses']),
      (440,410,730,595,'SOFTWARE_ASSIGNMENTS',['PK id','FK software_id','FK user_id']),
      (830,410,1090,595,'RISK_PREDICTIONS',['PK id','FK laptop_id','risk_score']),
      (1190,410,1500,595,'DEVICE_LOCATIONS',['PK id','FK laptop_id','FK created_by']),
      (230,785,510,970,'LOCATION_ALERTS',['PK id','FK laptop_id','FK location_id']),
      (690,785,950,970,'LAPTOP_HISTORY',['PK id','FK laptop_id','FK user_id, admin_id']),
      (1110,785,1390,970,'AUDIT_LOGS',['PK id','FK user_id','entity_type, action'])]
    for x1,y1,x2,y2,t,lines in ents: box(d,(x1,y1,x2,y2),t,lines)
    relationships=[((270,150),(390,150),'1 : M'),((650,150),(760,150),'1 : M'),((650,190),(1130,190),'1 : M'),((340,500),(440,500),'1 : M'),((650,245),(830,410),'1 : M'),((650,220),(1190,410),'1 : M'),((1350,595),(370,785),'1 : M'),((520,595),(810,785),'1 : M'),((270,225),(1110,870),'1 : M')]
    for s,e,l in relationships: arrow(d,s,e,l)
    im.save(p); return p

def class_diagram():
    im,d,p=canvas('class_diagram.png',1600,1100)
    classes=[
      (50,50,410,250,'User',['+ id: int','+ fullName: string','+ role: Role','+ authenticate()','+ checkInLocation()']),
      (600,50,990,275,'Laptop',['+ id: int','+ assetTag: string','+ status: AssetStatus','+ assignedTo: User','+ assign()','+ returnAsset()']),
      (1190,50,1540,250,'MaintenanceRecord',['+ id: int','+ issueDescription: text','+ status: RecordStatus','+ resolve()']),
      (50,450,410,670,'Software',['+ id: int','+ totalLicenses: int','+ status: LicenceStatus','+ seatsAvailable()']),
      (600,450,990,670,'SoftwareAssignment',['+ id: int','+ assignedDate: datetime','+ revokedAt: datetime','+ revoke()']),
      (1190,450,1540,700,'LocationCheckIn',['+ id: int','+ latitude, longitude','+ consentStatus','+ validateOwner()','+ assessApprovedArea()']),
      (600,820,990,1040,'MaintenanceRisk',['+ id: int','+ riskScore: decimal','+ riskLevel: RiskLevel','+ calculate()'])]
    for a,b,c,e,title,lines in classes: box(d,(a,b,c,e),title,lines,PALE)
    rel=[((410,150),(600,150),'assigned to'),((990,150),(1190,150),'has records'),((410,560),(600,560),'assigned through'),((990,220),(795,820),'produces'),((990,220),(1365,450),'has check-ins'),((990,560),(1190,560),'licensed to')]
    for s,e,l in rel: arrow(d,s,e,l)
    im.save(p); return p

def activity():
    im,d,p=canvas('activity.png',1500,980)
    d.ellipse((660,35,840,100),fill='#'+GREEN,outline='#'+BLUE,width=3); d.text((710,54),'Start',font=FB14,fill='#'+BLUE)
    nodes=[(535,150,965,230,'Administrator opens maintenance dashboard'),(535,290,965,370,'System loads asset, repair and usage data'),(535,430,965,510,'System calculates maintenance risk'),(535,650,965,730,'Create maintenance record and notify ICT'),(535,805,965,885,'Display risk result and retain audit entry')]
    for x1,y1,x2,y2,title in nodes: box(d,(x1,y1,x2,y2),title,(),PALE)
    for a,b in [((750,100),(750,150)),((750,230),(750,290)),((750,370),(750,430)),((750,510),(750,570)),((750,630),(750,650)),((750,730),(750,805))]: arrow(d,a,b)
    d.polygon([(750,550),(885,600),(750,650),(615,600)],fill='#'+ORANGE,outline='#'+BLUE)
    d.text((665,584),'High risk?',font=FB12,fill='#'+INK)
    arrow(d,(885,600),(1160,600),'Yes'); box(d,(1130,550,1450,640),'Mark asset for service',(),ORANGE,title_font=FB12)
    arrow(d,(750,650),(750,805),'No')
    arrow(d,(1290,640),(965,845))
    d.ellipse((665,905,835,965),fill='#'+GREEN,outline='#'+BLUE,width=3); d.text((712,922),'End',font=FB14,fill='#'+BLUE)
    arrow(d,(750,885),(750,905))
    im.save(p); return p

def set_font(run, size=11, bold=False, color=INK):
    run.font.name='Calibri'; run._element.rPr.rFonts.set(qn('w:ascii'),'Calibri'); run._element.rPr.rFonts.set(qn('w:hAnsi'),'Calibri'); run.font.size=Pt(size); run.bold=bold; run.font.color.rgb=RGBColor.from_string(color)

def shade(cell, fill):
    shd=OxmlElement('w:shd'); shd.set(qn('w:fill'),fill); cell._tc.get_or_add_tcPr().append(shd)

def margins(cell):
    tcPr=cell._tc.get_or_add_tcPr(); m=OxmlElement('w:tcMar'); tcPr.append(m)
    for side,val in [('top',100),('start',120),('bottom',100),('end',120)]:
        v=OxmlElement('w:'+side); v.set(qn('w:w'),str(val)); v.set(qn('w:type'),'dxa'); m.append(v)

def table(doc, headers, rows, widths):
    t=doc.add_table(rows=1, cols=len(headers)); t.style='Table Grid'; t.alignment=WD_TABLE_ALIGNMENT.LEFT; t.autofit=False
    grid=t._tbl.tblGrid
    for col,w in zip(grid.gridCol_lst,widths): col.set(qn('w:w'),str(w))
    for c,h,w in zip(t.rows[0].cells,headers,widths):
        shade(c,GREY); margins(c); c.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER; c.width=Pt(w/20); r=c.paragraphs[0].add_run(h); set_font(r,10,True,BLUE)
    for row in rows:
        cells=t.add_row().cells
        for c,value,w in zip(cells,row,widths):
            margins(c); c.vertical_alignment=WD_CELL_VERTICAL_ALIGNMENT.CENTER; c.width=Pt(w/20); p=c.paragraphs[0]; p.paragraph_format.space_after=Pt(0); r=p.add_run(value); set_font(r,9.4)
    doc.add_paragraph().paragraph_format.space_after=Pt(3)

def add_heading(doc, text, level): doc.add_paragraph(text, style=f'Heading {level}')
def add_para(doc, text):
    p=doc.add_paragraph(); p.paragraph_format.space_after=Pt(8); p.add_run(text); return p
def bullet(doc,text):
    p=doc.add_paragraph(style='List Bullet'); p.paragraph_format.space_after=Pt(4); p.add_run(text)

def build():
    figs=[use_case(),sequence(),erd(),class_diagram(),activity()]
    doc=Document(); sec=doc.sections[0]
    sec.top_margin=Inches(1); sec.bottom_margin=Inches(1); sec.left_margin=Inches(1); sec.right_margin=Inches(1)
    normal=doc.styles['Normal']; normal.font.name='Calibri'; normal._element.rPr.rFonts.set(qn('w:ascii'),'Calibri'); normal._element.rPr.rFonts.set(qn('w:hAnsi'),'Calibri'); normal.font.size=Pt(11); normal.paragraph_format.space_after=Pt(8); normal.paragraph_format.line_spacing=1.25
    for n,size,col,bef,aft in [('Heading 1',16,MID,18,10),('Heading 2',13,MID,12,6),('Heading 3',12,BLUE,8,4)]:
        s=doc.styles[n]; s.font.name='Calibri'; s._element.rPr.rFonts.set(qn('w:ascii'),'Calibri'); s._element.rPr.rFonts.set(qn('w:hAnsi'),'Calibri'); s.font.size=Pt(size); s.font.bold=True; s.font.color.rgb=RGBColor.from_string(col); s.paragraph_format.space_before=Pt(bef); s.paragraph_format.space_after=Pt(aft); s.paragraph_format.keep_with_next=True
    hp=sec.header.paragraphs[0]; hp.alignment=WD_ALIGN_PARAGRAPH.RIGHT; r=hp.add_run('Smart Asset Management System'); set_font(r,9,False,'666666')
    fp=sec.footer.paragraphs[0]; fp.alignment=WD_ALIGN_PARAGRAPH.RIGHT; r=fp.add_run('System Analysis and Design | '); set_font(r,9,False,'666666'); fld=OxmlElement('w:fldSimple'); fld.set(qn('w:instr'),'PAGE'); fp._p.append(fld)
    add_heading(doc,'CHAPTER FOUR: SYSTEM ANALYSIS AND DESIGN',1)
    add_heading(doc,'4.1 Introduction',2)
    add_para(doc,'This chapter translates the requirements of the Smart Asset Management System into an implementable design. The system is a web-based application for managing organisational ICT assets throughout their lifecycle. It supports secure user access, asset registration and allocation, maintenance monitoring, software-licence control, consent-based location check-ins, approved-area alerts, audit logging and management reporting. Predictive maintenance is implemented as decision support: recorded usage, condition and repair history are used to prioritise devices for review; the final maintenance decision remains with authorised ICT staff.')
    add_heading(doc,'4.2 System Requirements',2)
    add_para(doc,'The requirements below are derived from the implemented PHP/MySQL application and define the services and quality attributes required for correct use in an organisational setting.')
    add_heading(doc,'4.2.1 Functional Requirements',3)
    table(doc,['ID','Requirement'],[
      ('FR1','The system shall authenticate users and provide role-based access for users, administrators and super administrators.'),
      ('FR2','The system shall allow authorised administrators to register, edit, search and view laptop asset records with unique asset tags and serial numbers.'),
      ('FR3','The system shall allow authorised administrators to assign an available asset to an active user, return it, change its lifecycle status and retain assignment history.'),
      ('FR4','The system shall record maintenance issues, repair status and usage observations, and present a low, medium or high maintenance-risk result.'),
      ('FR5','The system shall manage software inventory, licence capacity, active user assignments and licence revocation.'),
      ('FR6','The system shall enable an assigned user to submit a location check-in only after explicitly granting browser location permission and confirming consent.'),
      ('FR7','The system shall compare an authorised check-in against active approved areas, create an alert for an out-of-area check-in and optionally send an email alert.'),
      ('FR8','The system shall provide dashboards, filtered asset views and CSV, PDF or Excel reports for assets, maintenance and software licences.'),
      ('FR9','The system shall maintain audit records for sensitive administrative actions and location-related events.'),
      ('FR10','The system shall allow the super administrator to manage accounts and change non-super-administrator roles.')
    ],[800,8560])
    add_heading(doc,'4.2.2 Non-functional Requirements',3)
    table(doc,['Category','Requirement'],[
      ('Security','Passwords shall be stored as secure hashes. State-changing requests shall be protected by CSRF validation, and database operations shall use prepared statements.'),
      ('Privacy','Location is collected only through an explicit user check-in and browser permission. The system stores last-known authorised locations; it does not perform continuous device tracking.'),
      ('Performance','Normal dashboard, search and record-update requests should complete within three seconds on the local organisational network under expected use.'),
      ('Usability','The interface shall present clear menus, dashboard summaries, validation feedback and role-appropriate actions for administrators and employees.'),
      ('Reliability','Asset, assignment, maintenance and licence transactions shall preserve referential integrity and retain history/audit records for accountability.'),
      ('Maintainability','The application shall use modular PHP pages, a shared bootstrap layer, documented database schema/migrations and separated CSS/JavaScript assets.'),
      ('Compatibility','The system shall run on a modern desktop browser with PHP, MySQL/MariaDB and Apache/XAMPP. Location check-in requires HTTPS in production.'),
      ('Scalability','Database indexes on common asset, history, location, risk and licence-assignment queries shall support growth in organisational records.')
    ],[1800,7560])
    add_heading(doc,'4.3 System Analysis Diagrams',2)
    add_heading(doc,'4.3.1 Use Case Diagram',3)
    add_para(doc,'The use case diagram identifies the main actors and the services available to them. Administrators operate the asset, maintenance, licensing, reporting and alert-management functions. Employees can view their allocation and initiate a consent-based check-in. Super administrators additionally control user roles.')
    doc.add_picture(str(figs[0]),width=Inches(6.35)); doc.paragraphs[-1].alignment=WD_ALIGN_PARAGRAPH.CENTER
    add_heading(doc,'4.3.2 Sequence Diagram',3)
    add_para(doc,'This sequence models the consent-based location check-in workflow. Validation occurs before location storage: the API checks the session, CSRF token and that the asset belongs to the signed-in employee. Approved-area evaluation can then create an alert without turning the feature into background tracking.')
    doc.add_picture(str(figs[1]),width=Inches(6.35)); doc.paragraphs[-1].alignment=WD_ALIGN_PARAGRAPH.CENTER
    add_heading(doc,'4.3.3 Entity Relationship Diagram',3)
    add_para(doc,'The ERD shows the principal persistent entities. Users are related to their assigned laptops, licence assignments, location check-ins and audit entries. Laptops are the central asset entity and are linked to maintenance, usage, risk, location, alert and history records.')
    doc.add_picture(str(figs[2]),width=Inches(6.35)); doc.paragraphs[-1].alignment=WD_ALIGN_PARAGRAPH.CENTER
    add_heading(doc,'4.3.4 Class Diagram',3)
    add_para(doc,'The class diagram is a conceptual representation of the application domain. It emphasises the responsibilities represented in the database-backed PHP workflow: identity and authorisation, asset lifecycle operations, maintenance risk calculation, software allocation and authorised check-in handling.')
    doc.add_picture(str(figs[3]),width=Inches(6.35)); doc.paragraphs[-1].alignment=WD_ALIGN_PARAGRAPH.CENTER
    add_heading(doc,'4.3.5 Activity Diagram',3)
    add_para(doc,'The activity diagram shows the maintenance-risk decision-support process. A high risk does not automatically retire or repair an asset; instead, it creates a maintenance record and prompts ICT review. The final result and administrative action remain auditable.')
    doc.add_picture(str(figs[4]),width=Inches(6.35)); doc.paragraphs[-1].alignment=WD_ALIGN_PARAGRAPH.CENTER
    doc.core_properties.title='System Analysis and Design - Smart Asset Management System'; doc.core_properties.author='Kisotu Samwel Lemayian'
    OUT.parent.mkdir(exist_ok=True); doc.save(OUT); print(OUT)

if __name__=='__main__': build()
