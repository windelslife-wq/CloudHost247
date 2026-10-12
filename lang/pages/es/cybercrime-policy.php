<?php
/** cybercrime-policy — es. English remains inline; legal review required before release. */
return array(
    'hero' => array(
        'title' => 'Política de detección de ciberdelincuencia',
        'subtitle' => 'Última actualización: 26 de abril de 2026',
    ),
    'introduction' => array(
        'content' => 'CloudHost247 Inc. («la Empresa») se compromete a garantizar que todos los dominios registrados, alojados o gestionados en nuestra plataforma no se utilicen para actividades ilegales, fraudulentas o maliciosas. Esta política de detección de ciberdelincuencia explica las medidas preventivas y de detección que empleamos para identificar actividades sospechosas o fraudulentas, los procedimientos para gestionar denuncias de ciberdelincuencia o abuso y cómo particulares y organizaciones pueden comunicar problemas relativos a servicios alojados en nuestra plataforma.',
    ),
    'sections' => array(
        array(
            'id' => 'scope',
            'title' => '1. Alcance',
            'content' => 'Esta política se aplica a:',
            'items' => array(
                'Todos los empleados, contratistas y agentes de CloudHost247 Inc.',
                'Todos los visitantes, usuarios y titulares de cuentas de la Plataforma.',
                'Todos los clientes registrados, registrantes y propietarios de dominios.',
                'Todos los denunciantes, organismos encargados de hacer cumplir la ley y terceros que presenten informes.',
            ),
        ),
        array(
            'id' => 'fraud-definition',
            'title' => '2. Definición de actividad fraudulenta',
            'content' => 'Un dominio o servicio fraudulento es cualquier nombre de dominio, sitio web o recurso alojado utilizado para cometer fraude, engaño o cualquier forma de ciberdelincuencia. Esto incluye, entre otros:',
            'items' => array(
                'Sitios de robo de identidad, suplantación o ingeniería social.',
                'Actividades relacionadas con el ciberterrorismo o material que promueva la violencia y el extremismo.',
                'Falsificación, adulteración de documentos o servicios de credenciales falsas.',
                'Fraudes de pago anticipado, phishing y operaciones de suplantación.',
                'Estafas financieras en línea, esquemas Ponzi y plataformas de fraude de inversión.',
                'Dominios diseñados para imitar bancos, plataformas fintech, procesadores de pagos o entidades financieras reconocidas.',
                'Distribución de malware, ransomware, spyware o infraestructura de mando de botnets.',
                'Mercados ilegales, comercio de datos robados o tráfico de bienes y servicios prohibidos.',
            ),
        ),
        array(
            'id' => 'monitoring',
            'title' => '3. Supervisión y detección',
            'content' => 'CloudHost247 Inc. emplea un enfoque multicapa para supervisar y detectar actividades sospechosas en nuestra infraestructura:',
            'items' => array(
                '<strong>Supervisión de red:</strong> Nuestros sistemas analizan continuamente el tráfico para detectar patrones anómalos, como conexiones salientes inusuales, transferencias de gran volumen y asociaciones con IP maliciosas conocidas.',
                '<strong>Revisiones del equipo de abuso:</strong> El equipo de abuso de CloudHost247 Inc. realiza supervisiones y revisiones de seguridad periódicas de dominios y servicios alojados en la Plataforma para identificar actividades sospechosas o potencialmente fraudulentas.',
                '<strong>Análisis de comportamiento:</strong> Supervisamos el comportamiento de las cuentas en busca de indicadores de compromiso, como ubicaciones de acceso inesperadas, intentos de fuerza bruta y patrones de uso automatizado de credenciales robadas.',
                '<strong>Inteligencia de terceros:</strong> Nos suscribimos a fuentes reputadas de inteligencia de amenazas, listas de bloqueo y avisos de seguridad para identificar dominios fraudulentos conocidos y nuevos vectores de ataque.',
                '<strong>Verificación del registrante:</strong> Los nuevos registros y pedidos de alto riesgo están sujetos a verificaciones automáticas y manuales para confirmar su legitimidad antes de activar plenamente el servicio.',
            ),
        ),
        array(
            'id' => 'data-collection',
            'title' => '4. Recopilación de datos para la detección',
            'content' => 'Para detectar y prevenir eficazmente la ciberdelincuencia, CloudHost247 Inc. puede recopilar y analizar las siguientes categorías de datos:',
            'items' => array(
                '<strong>Registros de servidor y acceso:</strong> Registros de solicitudes HTTP, autenticación, acceso FTP/SFTP e historial de acceso al panel de control.',
                '<strong>Datos de red:</strong> Direcciones IP, geolocalización, información ASN, marcas de tiempo de conexión y patrones de uso del ancho de banda.',
                '<strong>Metadatos de cuenta:</strong> Datos de registro, verificación del método de pago, información de contacto e historial de pedidos.',
                '<strong>Hashes de contenido:</strong> Hashes criptográficos de archivos alojados comparados con bases de contenido malicioso conocido para detectar distribución de malware sin acceder directamente al contenido de los archivos.',
                '<strong>Registros de comunicación:</strong> Tickets de soporte, informes de abuso y correspondencia relacionada con incidentes de seguridad.',
            ),
        ),
        array(
            'id' => 'automated',
            'title' => '5. Sistemas de detección automatizada',
            'content' => 'CloudHost247 Inc. utiliza herramientas automáticas y sistemas de inteligencia artificial para mejorar la detección de amenazas:',
            'items' => array(
                '<strong>Motores de detección de anomalías:</strong> Sistemas basados en IA analizan los niveles de referencia de tráfico y señalan desviaciones que pueden indicar un compromiso, actividad DDoS o uso no autorizado de recursos.',
                '<strong>Análisis de malware:</strong> Servicios automáticos de análisis inspeccionan periódicamente el contenido alojado en busca de firmas conocidas de malware, scripts de shell y patrones de código sospechosos.',
                '<strong>Detección de phishing:</strong> Modelos de aprendizaje automático evalúan dominios, contenido de páginas y similitud visual para detectar sitios de phishing que suplantan marcas de confianza.',
                '<strong>Filtros de spam y abuso:</strong> El tráfico de correo se supervisa mediante filtrado automático para detectar spam saliente, suplantación y campañas de fraude por correo.',
                '<strong>Limitación de frecuencia y bloqueo:</strong> Los sistemas automáticos aplican límites de frecuencia y pueden bloquear temporalmente las IP que muestren comportamientos de fuerza bruta, exploración o denegación de servicio.',
            ),
        ),
        array(
            'id' => 'user-responsibilities',
            'title' => '6. Responsabilidades del usuario',
            'content' => 'Todos los clientes y usuarios de servicios de CloudHost247 Inc. deben cumplir la ley y nuestra política de uso aceptable. En concreto, NO debe:',
            'items' => array(
                'Utilizar nuestros servicios para alojar, distribuir, promover o facilitar actividades ilegales, fraudulentas o dañinas.',
                'Registrar dominios o crear servicios destinados a engañar, suplantar o defraudar a terceros.',
                'Subir, transmitir o almacenar malware, kits de explotación, credenciales robadas o contenido ilegal.',
                'Lanzar o participar en ataques de denegación de servicio, exploraciones de puertos o intentos de intrusión no autorizados.',
                'Enviar correo masivo no solicitado (spam), mensajes de phishing o suplantar correos electrónicos.',
                'Intentar eludir nuestra supervisión, controles de seguridad o mecanismos de detección de abuso.',
                'Proporcionar información de identidad falsa, engañosa o robada durante el registro de la cuenta.',
            ),
        ),
        array(
            'id' => 'reporting',
            'title' => '7. Denuncia de ciberdelincuencia y actividad sospechosa',
            'content' => 'Si una persona u organización sospecha que un dominio o servicio alojado en CloudHost247 Inc. se utiliza con fines fraudulentos o delictivos, notifíquelo de inmediato:',
            'items' => array(
                '<strong>Correo electrónico:</strong> Envíe un informe detallado a <a href="mailto:support@cloudhost247.com">support@cloudhost247.com</a> o a <a href="mailto:abuse@cloudhost247.com">abuse@cloudhost247.com</a>.',
                '<strong>Asunto:</strong> Utilice «Cybercrime Report» o «Abuse Complaint» para una derivación más rápida.',
                '<strong>Datos requeridos:</strong> Su nombre y datos de contacto; el dominio, dirección IP o identificador del servicio; una descripción de la actividad sospechosa; pruebas o capturas de pantalla, si están disponibles; y cualquier marca de tiempo o identificador de transacción pertinente.',
                '<strong>Confidencialidad:</strong> La identidad del denunciante se mantiene confidencial en la medida permitida por la ley. No revelamos sus datos de contacto a la parte acusada salvo exigencia legal.',
            ),
        ),
        array(
            'id' => 'investigation',
            'title' => '8. Proceso de investigación',
            'content' => 'Tras recibir una denuncia o detectar actividad sospechosa, el equipo de abuso:',
            'items' => array(
                'Revisará y evaluará el informe o alerta dentro de 24–48 horas.',
                'Notificará al registrante o titular de la cuenta afectado cuando sea necesario y solicitará aclaraciones o documentación de apoyo.',
                'Solicitará documentos de verificación (registro mercantil, documentos de identidad o pruebas de uso legítimo) cuando se cuestione la finalidad del dominio.',
                'Si el registrante aporta información válida y verificable que confirme un uso legítimo, el dominio o servicio permanecerá activo.',
                'Si el registrante no responde dentro de 7 días laborables o no puede demostrar un uso legítimo, la Empresa se reserva el derecho de suspender inmediatamente el dominio o cancelar los servicios de alojamiento asociados.',
            ),
        ),
        array(
            'id' => 'enforcement',
            'title' => '9. Medidas de aplicación',
            'content' => 'CloudHost247 Inc. se reserva el derecho de adoptar las siguientes medidas cuando se confirme ciberdelincuencia, fraude o abuso:',
            'items' => array(
                '<strong>Suspensión temporal:</strong> Suspensión inmediata del dominio, sitio web o servicio para evitar daños continuados mientras se investiga.',
                '<strong>Terminación permanente:</strong> Terminación completa del alojamiento, resolución del dominio y acceso a la cuenta ante infracciones confirmadas.',
                '<strong>Conservación de datos:</strong> Retención de registros y contenidos pertinentes durante un periodo razonable para apoyar investigaciones de las autoridades.',
                '<strong>Comunicación a las autoridades:</strong> Los casos graves pueden, a nuestra discreción, remitirse a las autoridades locales, nacionales o internacionales competentes.',
                '<strong>Restricciones de red:</strong> Las direcciones o rangos IP vinculados a actividades maliciosas pueden bloquearse en el perímetro de la red para proteger a otros clientes.',
                '<strong>Sin reembolsos:</strong> Las cuentas terminadas por ciberdelincuencia o abuso pierden todo derecho de reembolso de servicios prepagados.',
            ),
        ),
        array(
            'id' => 'privacy',
            'title' => '10. Consideraciones de privacidad',
            'content' => 'Todas las actividades de supervisión y detección respetan la privacidad del usuario y cumplen las leyes de protección de datos aplicables:',
            'items' => array(
                'La supervisión se limita estrictamente a detectar amenazas de seguridad, fraude e infracciones de nuestra política de uso aceptable.',
                'No accedemos a datos privados, correos o bases de datos del cliente sin una base jurídica válida, como una orden judicial, o su consentimiento expreso para fines de soporte.',
                'Los datos recopilados para seguridad se conservan únicamente durante el tiempo necesario para la detección, investigación y obligaciones legales.',
                'Los datos de contacto del registrante se revelan solo cuando se exija legalmente mediante una orden judicial válida o solicitud formal de las autoridades.',
                'Cumplimos las normas aplicables de protección de datos, incluidas, entre otras, el GDPR, la CCPA y las leyes nacionales de privacidad pertinentes.',
            ),
        ),
        array(
            'id' => 'updates',
            'title' => '11. Actualizaciones de la política',
            'content' => 'CloudHost247 Inc. se reserva el derecho de actualizar o modificar esta política de detección de ciberdelincuencia en cualquier momento para reflejar cambios tecnológicos, amenazas, requisitos legales o prácticas operativas. Las actualizaciones se publicarán en esta página con una fecha de entrada en vigor revisada. Continuar usando nuestros servicios tras los cambios constituye la aceptación de la política actualizada. Recomendamos a todos los usuarios e interesados revisarla periódicamente.',
        ),
    ),
    'contact' => array(
        'title' => 'Información de contacto',
        'content' => 'Para denunciar abuso, fraude o actividad sospechosa relativa a un servicio alojado en CloudHost247 Inc., o si tiene preguntas sobre esta política, contacte con nuestro equipo de abuso y seguridad:',
        'portal' => 'portal de soporte',
    ),
);
