<?php
/** backup-policy — de. English remains inline; legal review required before release. */
return array(
    'hero' => array(
        'title' => 'Sicherungsrichtlinie',
        'subtitle' => 'Zuletzt aktualisiert: 26. April 2026',
    ),
    'introduction' => array(
        'content' => 'Diese Sicherungsrichtlinie beschreibt die Sicherungspraktiken und Verantwortlichkeiten für Dienste von CloudHost247 Inc. Unser Ziel ist es, Kundendaten durch systematische Sicherungsverfahren zu schützen und zugleich die Grenzen der Anbieterhaftung und die Pflichten der Nutzer klar festzulegen. Bitte lesen Sie diese Richtlinie sorgfältig, um zu verstehen, wie Ihre Daten geschützt werden und welche Schritte Sie zur Aufbewahrung eigener unabhängiger Kopien ergreifen sollten.',
    ),
    'sections' => array(
        array(
            'id' => 'responsibility',
            'title' => '1. Verantwortung für Sicherungen',
            'content' => 'Die Verantwortung für Sicherungen wird zwischen CloudHost247 Inc. und unseren Kunden geteilt:',
            'items' => array(
                '<strong>Verantwortung des Anbieters:</strong> CloudHost247 Inc. führt automatische Sicherungen der Hosting-Serverumgebungen als freiwillige Leistung und Maßnahme zur Wiederherstellung nach Katastrophen durch. Diese Sicherungen sollen die Dienstkontinuität bei Hardwareausfällen, systemweiter Datenbeschädigung oder katastrophalen Infrastrukturereignissen wiederherstellen.',
                '<strong>Verantwortung des Nutzers:</strong> Kunden sind letztlich dafür verantwortlich, eigene unabhängige Sicherungen aller auf unserer Infrastruktur gehosteten Websitedateien, Datenbanken, E-Mails und Anwendungsdaten zu führen. Unsere automatischen Sicherungen sollten nicht Ihre einzige oder primäre Datenschutzstrategie sein.',
                '<strong>Umfang:</strong> Vom Anbieter verwaltete Sicherungen umfassen Wiederherstellungspunkte auf Serverebene und erstrecken sich nicht auf einzelne Dateien, E-Mail-Wiederherstellung gelöschter Postfächer oder die gezielte Wiederherstellung einzelner Datenbanktabellen, sofern Ihr Tarif dies nicht ausdrücklich vorsieht.',
            ),
        ),
        array(
            'id' => 'frequency',
            'title' => '2. Sicherungshäufigkeit',
            'content' => 'Automatische Sicherungen werden nach folgendem Zeitplan durchgeführt:',
            'items' => array(
                '<strong>Shared-Hosting und Reseller-Hosting:</strong> Tägliche inkrementelle Sicherungen mit vollständiger wöchentlicher Zusammenführung jeden Sonntag um 02:00 UTC.',
                '<strong>VPS-Hosting:</strong> Wöchentliche vollständige Snapshots jeden Samstag um 03:00 UTC. VPS-Kunden können gegen Aufpreis häufigere Snapshots über das Control Panel konfigurieren.',
                '<strong>Dedizierte Server:</strong> Die Sicherungshäufigkeit hängt von der beim Kauf gewählten Stufe des verwalteten Supports ab. Verwaltete Standardtarife enthalten wöchentliche Sicherungen; Premiumtarife tägliche Sicherungen. Selbstverwaltete dedizierte Server enthalten keine Anbietersicherungen, sofern kein separates Sicherungs-Add-on gekauft wurde.',
                '<strong>Datenbanken:</strong> MySQL/MariaDB-Datenbanken auf Shared- und Reseller-Hosting sind im täglichen Sicherungszyklus enthalten. Eigenständige Datenbankdienste werden nach dem Zeitplan der Hosting-Stufe gesichert.',
            ),
        ),
        array(
            'id' => 'retention',
            'title' => '3. Datenaufbewahrung',
            'content' => 'Die Aufbewahrungsfristen für Sicherungen variieren je nach Diensttyp:',
            'items' => array(
                '<strong>Shared- und Reseller-Hosting:</strong> Tägliche Sicherungen werden 7 Tage, wöchentliche Sicherungen 30 Tage aufbewahrt.',
                '<strong>VPS-Snapshots:</strong> Wöchentliche Snapshots werden 14 Tage aufbewahrt. Zusätzliche Snapshot-Slots können zur Verlängerung der Aufbewahrung gekauft werden.',
                '<strong>Sicherungen dedizierter Server:</strong> Verwaltete Standardsicherungen werden 14 Tage, verwaltete Premiumsicherungen 30 Tage aufbewahrt.',
                '<strong>Abgelaufene oder gekündigte Konten:</strong> Alle Sicherungen eines gekündigten, beendeten oder abgelaufenen Kontos werden innerhalb von 72 Stunden nach Dienstdeaktivierung dauerhaft gelöscht. Für inaktive Konten werden keine Sicherungen aufbewahrt.',
                '<strong>Überschriebene Daten:</strong> Sicherungen folgen einem fortlaufenden Rotationszyklus. Sobald ein Sicherungszyklus abgeschlossen ist und neue Daten ältere Wiederherstellungspunkte überschreiben, können die vorherigen Daten nicht mehr wiederhergestellt werden.',
            ),
        ),
        array(
            'id' => 'restoration',
            'title' => '4. Wiederherstellungsrichtlinie',
            'content' => 'Wenn Sie eine Datenwiederherstellung benötigen, gelten folgende Verfahren und Einschränkungen:',
            'items' => array(
                '<strong>Wiederherstellungsanträge:</strong> Stellen Sie ein Support-Ticket über das Kundenportal und geben Sie den Dienst, das ungefähre Datum der benötigten Daten und den Antragsgrund an.',
                '<strong>Bearbeitungszeit:</strong> Standardanträge werden an Werktagen innerhalb von 24–48 Stunden bearbeitet. Notfallanträge können zusätzliche Gebühren verursachen.',
                '<strong>Wiederherstellungsumfang:</strong> Anbietersicherungen werden auf Konto- oder Serverebene wiederhergestellt. Wir führen keine selektive Wiederherstellung einzelner Dateien, Ordner oder E-Mails aus Systemsicherungen durch. Nutzer, die eine gezielte Wiederherstellung benötigen, sollten eigene unabhängige Sicherungen führen.',
                '<strong>Kosten:</strong> Wiederherstellungen aus Standardsicherungszyklen sind für verwaltete Dienste kostenlos enthalten. Wiederherstellungen außerhalb der regulären Aufbewahrungsfristen oder für selbstverwaltete Dienste ohne aktives Sicherungs-Add-on können stündliche technische Arbeitskosten verursachen.',
                '<strong>Keine Garantie:</strong> Obwohl wir alle angemessenen Anstrengungen zur Wahrung der Sicherungsintegrität unternehmen, können wir die Verfügbarkeit, Vollständigkeit oder Nutzbarkeit eines bestimmten Wiederherstellungspunkts zum Zeitpunkt des Antrags nicht garantieren.',
            ),
        ),
        array(
            'id' => 'limitations',
            'title' => '5. Einschränkungen und Haftung',
            'content' => 'CloudHost247 Inc. stellt Sicherungen nach bestem Bemühen bereit. Folgende Einschränkungen gelten:',
            'items' => array(
                'Unsere Sicherungssysteme dienen der Wiederherstellung nach Katastrophen und ersetzen keine kundeneigenen Datenschutzstrategien.',
                'Wir haften nicht für Datenverlust, Betriebsunterbrechung, Einnahmeverluste oder Folgeschäden durch Sicherungsfehler, unvollständige Sicherungen, verspätete Wiederherstellung oder fehlende Sicherungsverfügbarkeit.',
                'Sicherungen erfassen möglicherweise keine vor dem Sicherungsfenster beschädigten Daten, vom Kunden gelöschten Daten oder zwischen Sicherungsintervallen geänderten Daten.',
                'Wir sichern keine Inhalte, die gegen unsere Richtlinie zur akzeptablen Nutzung verstoßen, einschließlich Schadsoftware, raubkopiertem Material oder illegalen Inhalten. Solche Inhalte können ohne Vorankündigung von Sicherungen ausgeschlossen werden.',
                'Die maximale Gesamthaftung für jede sicherungsbezogene Forderung ist auf die anteilige monatliche Dienstgebühr des betroffenen Dienstes begrenzt.',
            ),
        ),
        array(
            'id' => 'user-responsibility',
            'title' => '6. Verantwortung des Nutzers',
            'content' => 'Alle Kunden werden dringend aufgefordert und es wird von ihnen erwartet:',
            'items' => array(
                'Regelmäßige unabhängige Sicherungen aller kritischen Daten auf unseren Servern zu führen, einschließlich Websitedateien, Datenbanken und E-Mail-Inhalten.',
                'Verfügbare Control-Panel-Werkzeuge (wie cPanel Backup Wizard, phpMyAdmin-Exporte oder FTP-Downloads) zur Erstellung und zum Herunterladen persönlicher Sicherungskopien zu nutzen.',
                'Wiederherstellungsverfahren regelmäßig zu testen, um sicherzustellen, dass eigene Sicherungen gültig und nutzbar sind.',
                'Mindestens eine Kopie kritischer Sicherungen außerhalb des Standorts oder an einem geografisch getrennten Ort aufzubewahren.',
                'Zu verstehen, dass die ausschließliche Nutzung von Anbietersicherungen Daten gefährdet und für geschäftskritische Anwendungen nicht empfohlen wird.',
            ),
        ),
        array(
            'id' => 'updates',
            'title' => '7. Aktualisierungen der Richtlinie',
            'content' => 'CloudHost247 Inc. behält sich das Recht vor, diese Sicherungsrichtlinie jederzeit zu aktualisieren oder zu ändern. Änderungen können Anpassungen der Sicherungshäufigkeit, Aufbewahrungsfristen, Wiederherstellungsverfahren oder des Leistungsumfangs umfassen. Aktualisierungen werden auf dieser Seite mit einem geänderten Gültigkeitsdatum veröffentlicht. Die weitere Nutzung unserer Dienste nach Richtlinienänderungen gilt als Zustimmung zu den aktualisierten Bedingungen. Wir empfehlen Kunden, diese Richtlinie regelmäßig zu prüfen.',
        ),
    ),
    'contact' => array(
        'title' => 'Kontaktieren Sie uns',
        'content' => 'Bei Fragen zu unserer Sicherungsrichtlinie oder wenn Sie Unterstützung bei der Datenwiederherstellung benötigen, wenden Sie sich bitte an unser Support-Team:',
        'portal' => 'Support-Portal',
    ),
);
