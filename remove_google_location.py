from docx import Document

PATH = 'deliverables/Smart_Asset_Management_Maintenance_Lifecycle.docx'

def delete_paragraph(paragraph):
    p = paragraph._element
    p.getparent().remove(p)
    paragraph._p = paragraph._element = None

def delete_row(row):
    tr = row._tr
    tr.getparent().remove(tr)

doc = Document(PATH)

# Replace mixed paragraphs so their maintenance/lifecycle content is retained.
prefix_replacements = {
    'Machine Learning Enabled Smart Asset Management System for Predictive Maintenance and Google-Based Device Location Tracking':
        'Machine Learning Enabled Smart Asset Management System for Predictive Maintenance and Asset Lifecycle Management',
    'The system will be developed using PHP, MySQL, HTML, CSS, JavaScript and XAMPP. It will support asset registration, assignment tracking, user and role management, status monitoring, maintenance records and report generation.':
        'The system will be developed using PHP, MySQL, HTML, CSS, JavaScript and XAMPP. It will support asset registration, assignment tracking, user and role management, lifecycle-status monitoring, maintenance records and report generation.',
    'To enhance security, the system will implement Two-Factor Authentication':
        'To enhance security, the system will implement Two-Factor Authentication (2FA) through email verification using PHPMailer and role-based access control. The machine-learning component will focus on predictive maintenance: estimating whether an ICT asset is likely to require maintenance within the next 30 days. Asset lifecycle management will record each asset’s active, under-maintenance and retired status. By analysing device age, repair history, warranty status and usage patterns, the system will help administrators identify high-risk assets before problems become serious.',
    'Keywords:':
        'Keywords: Smart Asset Management System, ICT Asset Management, Asset Tracking, Predictive Maintenance, Machine Learning, Asset Lifecycle Management, Two-Factor Authentication (2FA), Maintenance Records, Report Generation, PHP, MySQL.',
    'This project goes beyond basic asset tracking by introducing machine learning features':
        'This project goes beyond basic asset tracking by introducing a machine-learning predictive-maintenance feature. Machine-learning techniques can analyse historical maintenance patterns to identify assets likely to require attention, while lifecycle management records whether an asset is active, under maintenance or retired. In this way, the system supports both operational efficiency and proactive decision-making.',
    'However, many organisations still rely on manual or semi-digital methods':
        'However, many organisations still rely on manual or semi-digital methods such as spreadsheets, paper-based records and disconnected tools. These methods are prone to human error and do not provide reliable visibility into asset condition, assignment, maintenance history or lifecycle status. As a result, organisations may experience asset loss, delayed repairs, inaccurate records, poor accountability and inefficient use of ICT equipment.',
    'This project, therefore, seeks to develop a Smart Asset Management System':
        'This project, therefore, seeks to develop a Smart Asset Management System that automates asset registration, assignment, maintenance and lifecycle management while integrating machine learning for predictive maintenance and automated maintenance alerts.',
    'This study is justified because it proposes a Smart Asset Management System':
        'This study is justified because it proposes a Smart Asset Management System that integrates traditional asset management functions with predictive maintenance, lifecycle management and enhanced security features. The system will enable administrators to register and monitor assets, manage user assignments, record lifecycle status, generate reports and oversee maintenance through a centralised platform.',
    'This study focuses on the design and development of a web-based Smart Asset Management System':
        'This study focuses on the design and development of a web-based Smart Asset Management System for managing ICT assets within an organisation, including laptops, desktops, printers and related computing devices. The system will provide a centralised platform for asset registration, assignment, lifecycle-status monitoring, maintenance management, reporting, user management and secure authentication. It will incorporate predictive maintenance and asset lifecycle tracking. The system will manage asset details, condition, maintenance history, warranty information, assignment records and lifecycle status, using sample data where real organisational data is unavailable. The project excludes device-location services, procurement management, asset depreciation, barcode hardware integration, enterprise resource planning functions and advanced hardware repair diagnostics.',
    'The conceptual framework explains how the Smart Asset Management System':
        'The conceptual framework explains how the Smart Asset Management System receives operational data, processes and stores it, applies maintenance intelligence and lifecycle management, and produces management reports and alerts. The framework consists of inputs, the core web application, persistent data storage, predictive-maintenance intelligence and outputs. Governance controls—including role-based access, authentication and audit logging—apply across all components.',
    'The main inputs will include asset details, user details':
        'The main inputs will include asset details, user details, department information, assignment records, asset status, maintenance history, warranty information and system activity logs. These inputs provide the data needed for normal asset management, lifecycle tracking and predictive-maintenance analysis.',
    'The system receives hardware asset details':
        'The system receives hardware asset details such as asset tags, serial numbers, brands, models, departments, assignment records, asset status, purchase dates and warranty information. It also receives user and role information, maintenance records and usage metrics such as active hours, crash counts and battery health.',
    'The core application is a PHP web system':
        'The core application is a PHP web system that supports authentication, role-based access control, asset registration, assignment, lifecycle-status updates, maintenance reporting, usage-data recording, dashboards, report exports and predictive-maintenance alerts. Sensitive administrative activities are recorded in audit logs to improve accountability.',
    'The system uses a MySQL database':
        'The system uses a MySQL database to store users and roles, hardware assets, assignments, maintenance records, lifecycle-status history, daily usage records, maintenance-risk predictions and audit logs.',
    'This central storage ensures that the application can provide':
        'This central storage ensures that the application can provide traceable asset histories, lifecycle information, maintenance records and audit evidence for management decisions.',
    'The system produces accurate asset records':
        'The system produces accurate asset records, assignment and lifecycle information, maintenance records, maintenance-risk scores, audit trails and management reports. These outputs support efficient asset management, better maintenance prioritisation, stronger accountability and informed decision-making.',
    'This chapter has reviewed the current state of ICT asset management':
        'This chapter has reviewed the current state of ICT asset management, common challenges, existing solutions, machine-learning applications and the gaps that remain. The review shows that organisations need more than a basic asset register: they need systems that are secure, centralised and capable of supporting proactive maintenance and lifecycle decisions.',
    'Data will be collected from asset records':
        'Data will be collected from asset records, user assignment records, maintenance history, warranty information, asset-status updates and system activity logs. Synthetic maintenance data will be generated from the project’s documented asset and maintenance schema when complete organisational data is not available.',
    'The collected data will be cleaned':
        'The collected data will be cleaned, validated and organised before being used by the system. This will include removing duplicate records, handling missing values, standardising fields such as asset status and department names, and validating maintenance and warranty dates.',
    'This project adopts the Object-Oriented Analysis and Design':
        'This project adopts the Object-Oriented Analysis and Design (OOAD) paradigm to guide the analysis, design and implementation of the Smart Asset Management System. OOAD is appropriate because the system consists of interconnected modules: Authentication and Security, User Management, Asset Registration, Assignment Tracking, Maintenance Management, Asset Lifecycle Management, Reporting and Analytics, Alert Management and Machine-Learning API Integration.',
    'Second, the project incorporates machine learning features':
        'Second, the project incorporates predictive maintenance and lifecycle management. The predictive-maintenance model may require experimentation, tuning and refinement based on available asset data, while lifecycle tracking requires accurate status and maintenance histories. Agile development supports these evolving requirements through iterative development cycles.',
    'To apply machine learning techniques for predictive maintenance, asset lifecycle prediction, smart allocation, and anomaly detection':
        'To apply machine learning to predict whether an ICT asset is likely to require maintenance within the next 30 days and to track its lifecycle status',
    'Existing asset management systems have improved asset tracking and reporting':
        'Existing asset management systems have improved asset tracking and reporting; however, many primarily focus on record keeping and lack intelligent capabilities that support proactive maintenance planning and clear lifecycle visibility. Predictive maintenance and lifecycle management features are often absent. Additionally, some systems provide limited security controls, increasing the risk of unauthorised access and data manipulation.',
    'The machine-learning component will focus on one prediction task':
        'The machine-learning component will focus on one prediction task: predictive maintenance. Asset lifecycle tracking will be implemented through recorded status transitions (active, under maintenance and retired), rather than as a second predictive model.',
    'It has assets and administrative reporting as its primary features':
        'It has assets and administrative reporting as its primary features, but it does not offer an out-of-the-box predictive-maintenance model or a focused lifecycle-management workflow. This provides a chance to build a system that integrates regular asset management with maintenance prediction and lifecycle visibility.',
    "There's also a noticeable absence of smarter functionality":
        'There is also a noticeable absence of proactive maintenance and lifecycle-management functionality in many conventional systems. When such capabilities exist, they may require separate tools, custom configurations or third-party platforms, adding complexity and cost to an already involved process.',
    'Asset Lifecycle and Smart Allocation Boundaries':
        'Asset Lifecycle Tracking',
    'For this project, “asset lifecycle” remains a descriptive status field':
        'For this project, asset lifecycle tracking records measurable status transitions: active, under maintenance and retired. Administrators will update the status when an asset is issued, sent for maintenance, returned to service or retired. Lifecycle records will be evaluated for completeness and consistency against sampled maintenance and asset-status records.',
    'For the machine learning component, the project will also use a data-driven approach':
        'For the machine-learning component, the project will use a data-driven approach. Historical and operational asset data will be analysed to support the predictive-maintenance classification task.',
    'The system architecture diagram illustrates the major technical components':
        'The system architecture diagram illustrates the major technical components of the Smart Asset Management System and how they communicate. The presentation layer provides the user interface using HTML, CSS and JavaScript, while the PHP application handles authentication, business logic, asset management, reporting and communication with the database. MySQL provides persistent storage for users, assets, assignments, maintenance records, lifecycle-status history and other system information. The PHP application communicates with the Python-based machine-learning component through a Flask API. The Flask API receives asset-related data and passes it to the predictive-maintenance model. The resulting maintenance-risk predictions are returned to the PHP application and presented to the administrator through the dashboard and alert interfaces. This architecture follows the proposed separation between the main web application and the machine-learning service.',
    'Asset lifecycle prediction': 'Asset lifecycle tracking',
    'This module will provide predictive maintenance alerts':
        'This module will provide predictive-maintenance alerts, lifecycle-status reports and maintenance reports to support better asset-management decisions.',
    'The project uses Logistic Regression as the primary predictive-maintenance classifier':
        'The project uses Logistic Regression as the primary predictive-maintenance classifier because it produces an interpretable probability and shows how each feature is associated with maintenance risk. A Random Forest classifier will be trained only as a performance comparator for the same 30-day maintenance prediction; it is not a separate feature. The selected model will be the one that meets the stated validation criteria while remaining suitable for explanation to administrators. Python libraries such as scikit-learn, Pandas and NumPy will be used, with Flask serving as the integration layer between the model and the PHP-based system.',
    'The project implements one machine-learning function: predictive maintenance':
        'The project implements one machine-learning function: predictive maintenance. For each ICT asset, the model predicts the binary target maintenance_required_within_30_days (1 = a maintenance record or verified fault is expected within the next 30 days; 0 = no such event). The output is a probability and a Low, Medium or High risk label to help an administrator prioritise inspection, repair or replacement. Asset lifecycle tracking is implemented through recorded status transitions—active, under maintenance and retired—rather than as a second predictive model.',
    'The package diagram illustrates the logical organisation':
        'The package diagram illustrates the logical organisation of the Smart Asset Management System into related functional components. The major packages include Authentication and Security, User Management, Asset Management, Assignment and Tracking, Maintenance, Asset Lifecycle Management, Reporting and Analytics, and Machine-Learning API Integration. The packages are organised to separate responsibilities while allowing them to communicate where necessary. The Asset Management package interacts with the Assignment, Maintenance and Asset Lifecycle Management packages, while the Reporting and Analytics package obtains information from asset-related components. The API Integration package provides communication between the PHP application and the Logistic Regression predictive-maintenance model. This organisation supports modularity and makes the system easier to maintain and extend.',
    'Sprint planning is conducted at the beginning':
        'Sprint planning is conducted at the beginning of each development cycle. During this phase, the developer selects backlog items to be completed within the sprint and estimates the effort required. Early sprints focus on database development, authentication and core asset management functionality. Subsequent sprints implement maintenance-record workflows, lifecycle-status tracking, predictive maintenance, reporting and alerts.',
    'The sprint backlog contains the specific tasks':
        'The sprint backlog contains the specific tasks selected for implementation during a particular sprint. Examples include creating database tables, implementing user authentication, developing asset-tracking interfaces, recording maintenance events, updating lifecycle status, training the predictive-maintenance model and generating reports.',
    'The integrated increment represents the working version':
        'The integrated increment represents the working version of the Smart Asset Management System produced at the end of each sprint. The final integrated increment combines user authentication, role management, asset registration, assignment tracking, maintenance records, lifecycle-status tracking, predictive maintenance, reporting, audit trails and alerts.',
    'System evaluation will combine functional tests':
        'System evaluation will combine functional tests with a small, consented usability and task-based evaluation involving intended users such as ICT administrators. The same representative asset-management tasks will be completed using the prototype and the current spreadsheet/manual process where available. Measures will include task completion time for registering and reporting an asset; task success rate; record completeness; discrepancy rate between a sampled physical asset list and database records; time taken to identify overdue maintenance; and the number and resolution time of maintenance alerts. Participants will complete a short System Usability Scale questionnaire and provide feedback on the usefulness of maintenance-risk explanations.',
    'The system is designed as a web-based ICT asset management platform':
        'The system is designed as a web-based ICT asset management platform that enables authorised administrators to register, assign, monitor and manage organisational ICT assets. The system also provides user and role management, lifecycle-status tracking, maintenance history, reporting, alerts and secure authentication. In addition to conventional asset management capabilities, the system incorporates machine learning to support predictive maintenance.',
}

for p in list(doc.paragraphs):
    for prefix, replacement in prefix_replacements.items():
        if p.text.startswith(prefix):
            p.text = replacement
            break

# Delete paragraphs devoted only to device geolocation/Google functionality.
remove_terms = [
    'Google Maps', 'Google-Based', 'Google Find My Device', 'Geolocation API',
    'authorised location', 'authorized location', 'device-location', 'location check-in',
    'location dashboard', 'location history', 'last-known location', 'approved-area',
    'Location availability', 'Location-related inputs', 'Authorised Location and Notification Services',
    'Device Location Tracking', 'GPS –', 'FusedLocationProviderClient', 'Mozilla Developer Network',
    'track the device location', 'Google.'
]
for p in list(doc.paragraphs):
    if any(term.lower() in p.text.lower() for term in remove_terms):
        delete_paragraph(p)

# Remove obsolete anomaly-detection and smart-allocation material; the project
# now contains only predictive maintenance and lifecycle tracking.
remove_ml_scope_terms = [
    'Machine Learning in Anomaly Detection', 'Anomaly detection will be used',
    'Machine learning can also be used in security', 'Anomaly detection',
    'Recommend Asset Allocation', 'Location-record database design'
]
for p in list(doc.paragraphs):
    if any(term.lower() in p.text.lower() for term in remove_ml_scope_terms):
        delete_paragraph(p)

# The conceptual-framework image included the removed location component.
for index, p in enumerate(list(doc.paragraphs)):
    if p._p.xpath('.//w:drawing') and p.text == '' and index < 300:
        # Only the first content image is the conceptual framework; retain the Scrum and appendix images.
        delete_paragraph(p)

# Delete rows in comparison tables that advertise removed functionality.
for table in doc.tables:
    for row in list(table.rows):
        row_text = ' '.join(cell.text for cell in row.cells).lower()
        if any(term in row_text for term in ['google maps', 'location tracking', 'location history', 'approved-area', 'smart asset allocation', 'anomaly detection']):
            delete_row(row)
        else:
            for cell in row.cells:
                if cell.text.strip() == 'Asset Lifecycle Prediction':
                    cell.paragraphs[0].text = 'Asset Lifecycle Tracking'

# Remove obsolete Google/device-location references from headers and footers.
for section in doc.sections:
    for container in (section.header, section.footer):
        for p in list(container.paragraphs):
            if any(term.lower() in p.text.lower() for term in remove_terms):
                delete_paragraph(p)

doc.save(PATH)
