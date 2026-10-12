<?php
/** backup-policy — es. English remains inline; legal review required before release. */
return array(
    'hero' => array(
        'title' => 'Política de copias de seguridad',
        'subtitle' => 'Última actualización: 26 de abril de 2026',
    ),
    'introduction' => array(
        'content' => 'Esta política de copias de seguridad describe las prácticas y responsabilidades de respaldo de los servicios prestados por CloudHost247 Inc. Nuestro objetivo es proteger los datos de los clientes mediante procedimientos sistemáticos de copia de seguridad, definiendo claramente los límites de responsabilidad del proveedor y las obligaciones del usuario. Revise esta política detenidamente para comprender cómo se protegen sus datos y qué medidas debe tomar para mantener sus propias copias independientes.',
    ),
    'sections' => array(
        array(
            'id' => 'responsibility',
            'title' => '1. Responsabilidad de las copias de seguridad',
            'content' => 'La responsabilidad de las copias de seguridad se comparte entre CloudHost247 Inc. y nuestros clientes:',
            'items' => array(
                '<strong>Responsabilidad del proveedor:</strong> CloudHost247 Inc. realiza copias automáticas de los entornos de servidores de alojamiento como cortesía y medida de recuperación ante desastres. Estas copias están destinadas a restablecer la continuidad del servicio en caso de fallos de hardware, corrupción del sistema o sucesos catastróficos de infraestructura.',
                '<strong>Responsabilidad del usuario:</strong> Los clientes son los responsables finales de mantener sus propias copias independientes de todos los archivos web, bases de datos, correos y datos de aplicaciones alojados en nuestra infraestructura. Nuestras copias automáticas no deben considerarse su estrategia única o principal de protección de datos.',
                '<strong>Alcance:</strong> Las copias gestionadas por el proveedor cubren puntos de restauración a nivel de servidor y no incluyen la recuperación de archivos individuales, la restauración de correos de buzones eliminados ni la recuperación granular de tablas de bases de datos, salvo indicación expresa en su plan de servicio.',
            ),
        ),
        array(
            'id' => 'frequency',
            'title' => '2. Frecuencia de las copias de seguridad',
            'content' => 'Las copias automáticas se realizan según el siguiente calendario:',
            'items' => array(
                '<strong>Alojamiento compartido y de revendedores:</strong> Copias incrementales diarias con una consolidación completa semanal cada domingo a las 02:00 UTC.',
                '<strong>Alojamiento VPS:</strong> Instantáneas completas semanales cada sábado a las 03:00 UTC. Los clientes VPS pueden configurar instantáneas más frecuentes mediante el panel de control por un coste adicional.',
                '<strong>Servidores dedicados:</strong> La frecuencia depende del nivel de soporte gestionado elegido al comprar. Los planes gestionados estándar incluyen copias semanales; los premium incluyen copias diarias. Los servidores dedicados autogestionados no incluyen copias del proveedor salvo que se adquiera un complemento de respaldo separado.',
                '<strong>Bases de datos:</strong> Las bases MySQL/MariaDB de alojamiento compartido y de revendedores se incluyen en el ciclo diario de copias. Los servicios independientes de bases de datos se respaldan según el calendario del nivel de alojamiento.',
            ),
        ),
        array(
            'id' => 'retention',
            'title' => '3. Retención de datos',
            'content' => 'Los periodos de retención de copias varían según el tipo de servicio:',
            'items' => array(
                '<strong>Alojamiento compartido y de revendedores:</strong> Copias diarias conservadas durante 7 días; copias semanales conservadas durante 30 días.',
                '<strong>Instantáneas VPS:</strong> Instantáneas semanales conservadas durante 14 días. Pueden comprarse espacios adicionales de instantáneas para ampliar la retención.',
                '<strong>Copias de servidores dedicados:</strong> Copias gestionadas estándar conservadas durante 14 días; copias gestionadas premium durante 30 días.',
                '<strong>Cuentas caducadas o canceladas:</strong> Todas las copias asociadas a una cuenta cancelada, terminada o caducada se eliminan permanentemente dentro de las 72 horas posteriores a la desactivación del servicio. No se conservan copias de cuentas inactivas.',
                '<strong>Datos sobrescritos:</strong> Las copias funcionan en un ciclo de rotación continuo. Una vez finalizado un ciclo y sobrescritos los puntos antiguos por nuevos datos, los datos anteriores no pueden recuperarse.',
            ),
        ),
        array(
            'id' => 'restoration',
            'title' => '4. Política de restauración',
            'content' => 'Si necesita restaurar datos, se aplican el siguiente proceso y limitaciones:',
            'items' => array(
                '<strong>Solicitudes de restauración:</strong> Envíe un ticket de soporte mediante el portal del cliente indicando el servicio, la fecha aproximada de los datos que necesita restaurar y el motivo de la solicitud.',
                '<strong>Tiempo de procesamiento:</strong> Las solicitudes estándar se procesan dentro de 24–48 horas durante días hábiles. Las solicitudes urgentes pueden generar cargos adicionales.',
                '<strong>Alcance de restauración:</strong> Las copias del proveedor se restauran a nivel de cuenta o servidor. No realizamos restauraciones selectivas archivo por archivo, por carpeta ni de correos individuales desde copias del sistema. Los usuarios que necesiten recuperación granular deben mantener sus propias copias independientes.',
                '<strong>Coste:</strong> Las restauraciones de ciclos estándar se incluyen sin cargo en los servicios gestionados. Las restauraciones fuera de los plazos normales de retención o para servicios autogestionados sin complemento de respaldo activo pueden estar sujetas a tarifas horarias de trabajo técnico.',
                '<strong>Sin garantía:</strong> Aunque hacemos todos los esfuerzos razonables para mantener la integridad de las copias, no podemos garantizar la disponibilidad, integridad o utilidad de ningún punto de restauración concreto en el momento de la solicitud.',
            ),
        ),
        array(
            'id' => 'limitations',
            'title' => '5. Limitaciones y responsabilidad',
            'content' => 'CloudHost247 Inc. proporciona copias de seguridad con el máximo esfuerzo razonable. Se aplican las siguientes limitaciones:',
            'items' => array(
                'Nuestros sistemas de respaldo están diseñados para recuperación ante desastres, no para sustituir las estrategias de protección de datos del cliente.',
                'No somos responsables de pérdidas de datos, interrupción del negocio, pérdidas de ingresos ni daños consecuentes derivados de fallos de respaldo, copias incompletas, restauraciones retrasadas o indisponibilidad de copias.',
                'Las copias pueden no capturar datos dañados antes de la ventana de respaldo, datos eliminados por el cliente o datos modificados entre los intervalos de copia.',
                'No respaldamos contenido que infrinja nuestra política de uso aceptable, incluido malware, material pirateado o contenido ilegal. Dicho contenido puede excluirse de las copias sin previo aviso.',
                'La responsabilidad total máxima por cualquier reclamación relacionada con las copias se limita a la cuota mensual proporcional del servicio afectado.',
            ),
        ),
        array(
            'id' => 'user-responsibility',
            'title' => '6. Responsabilidad del usuario',
            'content' => 'Se recomienda encarecidamente y se espera que todos los clientes:',
            'items' => array(
                'Mantengan copias periódicas e independientes de todos los datos críticos almacenados en nuestros servidores, incluidos archivos web, bases de datos y correos.',
                'Utilicen las herramientas disponibles del panel de control (como cPanel Backup Wizard, exportaciones de phpMyAdmin o descargas FTP) para crear y descargar copias personales.',
                'Prueben periódicamente los procedimientos de restauración para asegurar que sus copias independientes sean válidas y utilizables.',
                'Guarden al menos una copia de los respaldos críticos en un lugar externo o geográficamente separado.',
                'Comprendan que depender únicamente de las copias gestionadas por el proveedor pone sus datos en riesgo y no se recomienda para aplicaciones esenciales del negocio.',
            ),
        ),
        array(
            'id' => 'updates',
            'title' => '7. Actualizaciones de la política',
            'content' => 'CloudHost247 Inc. se reserva el derecho de actualizar o modificar esta política de copias de seguridad en cualquier momento. Los cambios pueden incluir ajustes de frecuencia, periodos de retención, procedimientos de restauración o alcance de cobertura. Las actualizaciones se publicarán en esta página con una fecha de entrada en vigor revisada. Continuar usando nuestros servicios tras los cambios constituye la aceptación de las condiciones actualizadas. Recomendamos a los clientes revisar periódicamente esta política.',
        ),
    ),
    'contact' => array(
        'title' => 'Contáctenos',
        'content' => 'Si tiene preguntas sobre nuestra política de copias de seguridad o necesita ayuda con la restauración de datos, contacte con nuestro equipo de soporte:',
        'portal' => 'portal de soporte',
    ),
);
