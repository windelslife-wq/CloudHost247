<?php
/** cybercrime-policy — de. English remains inline; legal review required before release. */
return array(
    'hero' => array(
        'title' => 'Richtlinie zur Erkennung von Cyberkriminalität',
        'subtitle' => 'Zuletzt aktualisiert: 26. April 2026',
    ),
    'introduction' => array(
        'content' => 'CloudHost247 Inc. („das Unternehmen“) verpflichtet sich sicherzustellen, dass alle auf unserer Plattform registrierten, gehosteten oder verwalteten Domains nicht für illegale, betrügerische oder schädliche Aktivitäten genutzt werden. Diese Richtlinie zur Erkennung von Cyberkriminalität erläutert unsere Präventions- und Erkennungsmaßnahmen für verdächtige oder betrügerische Aktivitäten, die Verfahren zur Bearbeitung von Beschwerden über Cyberkriminalität oder Missbrauch und wie Einzelpersonen und Organisationen Bedenken zu auf unserer Plattform gehosteten Diensten melden können.',
    ),
    'sections' => array(
        array(
            'id' => 'scope',
            'title' => '1. Geltungsbereich',
            'content' => 'Diese Richtlinie gilt für:',
            'items' => array(
                'Alle Mitarbeitenden, Auftragnehmer und Vertreter von CloudHost247 Inc.',
                'Alle Besucher, Nutzer und Kontoinhaber der Plattform.',
                'Alle registrierten Kunden, Registranten und Domaininhaber.',
                'Alle Beschwerdeführer, Strafverfolgungsbehörden und meldenden Dritten.',
            ),
        ),
        array(
            'id' => 'fraud-definition',
            'title' => '2. Definition betrügerischer Aktivitäten',
            'content' => 'Eine betrügerische Domain oder ein betrügerischer Dienst bezeichnet jeden Domainnamen, jede Website oder gehostete Ressource, die für Betrug, Täuschung oder jegliche Form von Cyberkriminalität genutzt wird. Dazu gehören unter anderem:',
            'items' => array(
                'Websites für Identitätsdiebstahl, Identitätsvortäuschung oder Social Engineering.',
                'Aktivitäten im Zusammenhang mit Cyberterrorismus oder Material, das Gewalt und Extremismus fördert.',
                'Fälschungen, Dokumentenfälschung oder Dienste für gefälschte Nachweise.',
                'Vorschussbetrug, Phishing und Spoofing-Operationen.',
                'Online-Finanzbetrug, Ponzi-Systeme und betrügerische Anlageplattformen.',
                'Domains, die Banken, Fintech-Plattformen, Zahlungsabwickler oder anerkannte Finanzinstitute nachahmen sollen.',
                'Verbreitung von Schadsoftware, Ransomware, Spyware oder Botnet-Steuerungsinfrastruktur.',
                'Illegale Marktplätze, Handel mit gestohlenen Daten oder Handel mit verbotenen Waren und Diensten.',
            ),
        ),
        array(
            'id' => 'monitoring',
            'title' => '3. Überwachung und Erkennung',
            'content' => 'CloudHost247 Inc. nutzt einen mehrstufigen Ansatz zur Überwachung und Erkennung verdächtiger Aktivitäten in unserer Infrastruktur:',
            'items' => array(
                '<strong>Netzwerküberwachung:</strong> Unsere Systeme analysieren den Netzwerkverkehr fortlaufend auf ungewöhnliche Muster, darunter ungewöhnliche ausgehende Verbindungen, große Datenübertragungen und Verbindungen zu bekannten schädlichen IP-Adressen.',
                '<strong>Prüfungen des Missbrauchsteams:</strong> Das Missbrauchsteam von CloudHost247 Inc. überwacht und prüft regelmäßig die Sicherheit der auf der Plattform gehosteten Domains und Dienste, um verdächtige oder möglicherweise betrügerische Aktivitäten zu erkennen.',
                '<strong>Verhaltensanalyse:</strong> Wir überwachen Kontoverhalten auf Hinweise einer Kompromittierung, etwa unerwartete Anmeldeorte, Brute-Force-Versuche und Muster automatisierter Nutzung gestohlener Zugangsdaten.',
                '<strong>Informationen Dritter:</strong> Wir beziehen seriöse Bedrohungsinformationen, Sperrlisten und Sicherheitshinweise, um bekannte betrügerische Domains und neue Angriffsvektoren zu erkennen.',
                '<strong>Registrantenprüfung:</strong> Neue Registrierungen und risikoreiche Bestellungen unterliegen automatischen und manuellen Prüfungen zur Bestätigung ihrer Legitimität vor vollständiger Dienstaktivierung.',
            ),
        ),
        array(
            'id' => 'data-collection',
            'title' => '4. Datenerhebung zur Erkennung',
            'content' => 'Zur wirksamen Erkennung und Verhinderung von Cyberkriminalität kann CloudHost247 Inc. folgende Datenkategorien erheben und analysieren:',
            'items' => array(
                '<strong>Server- und Zugriffsprotokolle:</strong> HTTP-Anfrageprotokolle, Authentifizierungsprotokolle, FTP/SFTP-Zugriffsaufzeichnungen und Control-Panel-Anmeldeverlauf.',
                '<strong>Netzwerkdaten:</strong> IP-Adressen, Standortdaten, ASN-Informationen, Verbindungszeitstempel und Bandbreitennutzungsmuster.',
                '<strong>Kontometadaten:</strong> Registrierungsdaten, Daten zur Zahlungsmethodenprüfung, Kontaktinformationen und Bestellverlauf.',
                '<strong>Inhaltshashes:</strong> Kryptografische Hashes gehosteter Dateien, die mit Datenbanken bekannter schädlicher Inhalte verglichen werden, um Schadsoftwareverbreitung zu erkennen, ohne direkt auf Dateiinhalte zuzugreifen.',
                '<strong>Kommunikationsaufzeichnungen:</strong> Support-Tickets, Missbrauchsmeldungen und Korrespondenz zu Sicherheitsvorfällen.',
            ),
        ),
        array(
            'id' => 'automated',
            'title' => '5. Automatische Erkennungssysteme',
            'content' => 'CloudHost247 Inc. nutzt automatische Werkzeuge und Systeme künstlicher Intelligenz zur Verbesserung der Bedrohungserkennung:',
            'items' => array(
                '<strong>Anomalieerkennung:</strong> KI-gestützte Systeme analysieren Verkehrsgrundwerte und kennzeichnen Abweichungen, die auf Kompromittierung, DDoS-Aktivität oder unbefugte Ressourcennutzung hinweisen können.',
                '<strong>Schadsoftwareprüfung:</strong> Automatische Dateiprüfdienste untersuchen gehostete Inhalte regelmäßig auf bekannte Schadsoftwaresignaturen, Shell-Skripte und verdächtige Codemuster.',
                '<strong>Phishing-Erkennung:</strong> Modelle des maschinellen Lernens bewerten Domainnamen, Seiteninhalte und visuelle Ähnlichkeiten, um Phishing-Seiten zu erkennen, die vertrauenswürdige Marken nachahmen.',
                '<strong>Spam- und Missbrauchsfilter:</strong> E-Mail-Verkehr wird automatisch gefiltert, um ausgehenden Spam, Spoofing und E-Mail-Betrugskampagnen zu erkennen.',
                '<strong>Ratenbegrenzung und Sperrung:</strong> Automatische Systeme setzen Ratenlimits durch und können IP-Adressen mit Brute-Force-, Scan- oder Denial-of-Service-Verhalten vorübergehend sperren.',
            ),
        ),
        array(
            'id' => 'user-responsibilities',
            'title' => '6. Pflichten der Nutzer',
            'content' => 'Alle Kunden und Nutzer der Dienste von CloudHost247 Inc. müssen das Gesetz und unsere Richtlinie zur akzeptablen Nutzung einhalten. Insbesondere dürfen Sie NICHT:',
            'items' => array(
                'Unsere Dienste zum Hosten, Verbreiten, Fördern oder Ermöglichen illegaler, betrügerischer oder schädlicher Aktivitäten nutzen.',
                'Domains registrieren oder Dienste erstellen, die Dritte täuschen, deren Identität vortäuschen oder sie betrügen sollen.',
                'Schadsoftware, Exploit-Kits, gestohlene Zugangsdaten oder illegale Inhalte hochladen, übertragen oder speichern.',
                'Denial-of-Service-Angriffe, Portscans oder unbefugte Eindringversuche starten oder daran teilnehmen.',
                'Unverlangte Massen-E-Mails (Spam) oder Phishing-Nachrichten senden oder E-Mail-Spoofing betreiben.',
                'Versuchen, unsere Überwachung, Sicherheitskontrollen oder Missbrauchserkennung zu umgehen.',
                'Bei der Kontoregistrierung falsche, irreführende oder gestohlene Identitätsinformationen angeben.',
            ),
        ),
        array(
            'id' => 'reporting',
            'title' => '7. Meldung von Cyberkriminalität und verdächtigen Aktivitäten',
            'content' => 'Wenn eine Person oder Organisation vermutet, dass eine bei CloudHost247 Inc. gehostete Domain oder ein Dienst für betrügerische oder kriminelle Zwecke genutzt wird, melden Sie dies bitte umgehend:',
            'items' => array(
                '<strong>E-Mail:</strong> Senden Sie einen ausführlichen Bericht an <a href="mailto:support@cloudhost247.com">support@cloudhost247.com</a> oder <a href="mailto:abuse@cloudhost247.com">abuse@cloudhost247.com</a>.',
                '<strong>Betreff:</strong> Verwenden Sie „Cybercrime Report“ oder „Abuse Complaint“ für eine schnellere Zuordnung.',
                '<strong>Erforderliche Angaben:</strong> Ihr Name und Ihre Kontaktdaten; der betreffende Domainname, die IP-Adresse oder Dienstkennung; eine Beschreibung der vermuteten Aktivität; Belege oder Screenshots, sofern verfügbar; sowie relevante Zeitstempel oder Transaktionskennungen.',
                '<strong>Vertraulichkeit:</strong> Die Identität der meldenden Person bleibt im gesetzlich zulässigen Umfang vertraulich. Wir geben Kontaktdaten von Beschwerdeführern nur bei gesetzlicher Verpflichtung an die beschuldigte Partei weiter.',
            ),
        ),
        array(
            'id' => 'investigation',
            'title' => '8. Untersuchungsverfahren',
            'content' => 'Nach Eingang einer Beschwerde oder Erkennung verdächtiger Aktivitäten wird das Missbrauchsteam:',
            'items' => array(
                'Den Bericht oder die Warnung innerhalb von 24–48 Stunden prüfen und bewerten.',
                'Den betroffenen Registranten oder Kontoinhaber bei Bedarf benachrichtigen und um Klärung oder Nachweise bitten.',
                'Prüfunterlagen (Unternehmensregistrierung, Identitätsdokumente oder Nachweis legitimer Nutzung) anfordern, wenn der Domainzweck fraglich ist.',
                'Wenn der Registrant gültige und überprüfbare Angaben vorlegt, die eine legitime Nutzung bestätigen, bleibt die Domain oder der Dienst aktiv.',
                'Wenn der Registrant nicht innerhalb von 7 Werktagen antwortet oder keine legitime Nutzung nachweisen kann, behält sich das Unternehmen vor, die Domain sofort zu sperren oder zugehörige Hosting-Dienste zu beenden.',
            ),
        ),
        array(
            'id' => 'enforcement',
            'title' => '9. Durchsetzungsmaßnahmen',
            'content' => 'CloudHost247 Inc. behält sich vor, bei bestätigter Cyberkriminalität, bestätigtem Betrug oder Missbrauch folgende Maßnahmen zu ergreifen:',
            'items' => array(
                '<strong>Vorübergehende Sperrung:</strong> Sofortige Sperrung der Domain, Website oder des Dienstes, um während der Untersuchung weitere Schäden zu verhindern.',
                '<strong>Dauerhafte Beendigung:</strong> Vollständige Beendigung von Hosting-Diensten, Domainauflösung und Kontozugriff bei bestätigten Verstößen.',
                '<strong>Datensicherung:</strong> Aufbewahrung relevanter Protokolle und Inhalte für einen angemessenen Zeitraum zur Unterstützung behördlicher Untersuchungen.',
                '<strong>Meldung an Strafverfolgungsbehörden:</strong> Schwere Fälle können nach unserem Ermessen an zuständige lokale, nationale oder internationale Strafverfolgungsbehörden weitergegeben werden.',
                '<strong>Netzwerkbeschränkungen:</strong> IP-Adressen oder Bereiche im Zusammenhang mit schädlichen Aktivitäten können am Netzwerkrand gesperrt werden, um andere Kunden zu schützen.',
                '<strong>Keine Rückerstattungen:</strong> Konten, die wegen Cyberkriminalität oder Missbrauch beendet werden, verlieren jeden Anspruch auf Erstattung vorausbezahlter Dienste.',
            ),
        ),
        array(
            'id' => 'privacy',
            'title' => '10. Datenschutzaspekte',
            'content' => 'Alle Überwachungs- und Erkennungsaktivitäten erfolgen unter Achtung der Privatsphäre der Nutzer und im Einklang mit geltenden Datenschutzgesetzen:',
            'items' => array(
                'Die Überwachung ist eng auf die Erkennung von Sicherheitsbedrohungen, Betrug und Verstößen gegen unsere Richtlinie zur akzeptablen Nutzung begrenzt.',
                'Wir greifen nicht ohne gültige Rechtsgrundlage, etwa eine gerichtliche Anordnung, oder ausdrückliche Kundeneinwilligung für Supportzwecke auf private Kundendaten, E-Mails oder Datenbanken zu.',
                'Für Sicherheitszwecke erhobene Daten werden nur so lange aufbewahrt, wie für Erkennung, Untersuchung und etwaige gesetzliche Pflichten erforderlich.',
                'Kontaktdaten von Registranten werden nur weitergegeben, wenn eine gültige gerichtliche Anordnung oder formelle Anfrage einer Strafverfolgungsbehörde dies rechtlich verlangt.',
                'Wir erfüllen geltende Datenschutzvorschriften, einschließlich unter anderem GDPR, CCPA und einschlägiger nationaler Datenschutzgesetze.',
            ),
        ),
        array(
            'id' => 'updates',
            'title' => '11. Aktualisierungen der Richtlinie',
            'content' => 'CloudHost247 Inc. behält sich das Recht vor, diese Richtlinie zur Erkennung von Cyberkriminalität jederzeit zu aktualisieren oder zu ändern, um Änderungen von Technologie, Bedrohungslage, gesetzlichen Anforderungen oder betrieblichen Praktiken zu berücksichtigen. Aktualisierungen werden auf dieser Seite mit einem geänderten Gültigkeitsdatum veröffentlicht. Die weitere Nutzung unserer Dienste nach Änderungen gilt als Zustimmung zur aktualisierten Richtlinie. Wir empfehlen allen Nutzern und Beteiligten, diese Richtlinie regelmäßig zu prüfen.',
        ),
    ),
    'contact' => array(
        'title' => 'Kontaktinformationen',
        'content' => 'Um Missbrauch, Betrug oder verdächtige Aktivitäten im Zusammenhang mit einem bei CloudHost247 Inc. gehosteten Dienst zu melden oder bei Fragen zu dieser Richtlinie, wenden Sie sich an unser Missbrauchs- und Sicherheitsteam:',
        'portal' => 'Support-Portal',
    ),
);
