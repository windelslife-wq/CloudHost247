<?php
/** cybercrime-policy — fr. English remains inline; legal review required before release. */
return array(
    'hero' => array(
        'title' => 'Politique de détection de la cybercriminalité',
        'subtitle' => 'Dernière mise à jour : 26 avril 2026',
    ),
    'introduction' => array(
        'content' => 'CloudHost247 Inc. (« la Société ») s’engage à veiller à ce que tous les domaines enregistrés, hébergés ou gérés sur notre plateforme ne soient pas utilisés pour des activités illégales, frauduleuses ou malveillantes. Cette politique de détection de la cybercriminalité explique les mesures de prévention et de détection que nous employons pour identifier les activités suspectes ou frauduleuses, les procédures de traitement des plaintes liées à la cybercriminalité ou aux abus, et la manière dont les particuliers et les organisations peuvent signaler des préoccupations concernant les services hébergés sur notre plateforme.',
    ),
    'sections' => array(
        array(
            'id' => 'scope',
            'title' => '1. Champ d’application',
            'content' => 'Cette politique s’applique à :',
            'items' => array(
                'Tous les employés, prestataires et agents de CloudHost247 Inc.',
                'Tous les visiteurs, utilisateurs et titulaires de comptes de la Plateforme.',
                'Tous les clients enregistrés, titulaires d’enregistrements et propriétaires de domaines.',
                'Tous les plaignants, autorités chargées de l’application de la loi et tiers effectuant des signalements.',
            ),
        ),
        array(
            'id' => 'fraud-definition',
            'title' => '2. Définition des activités frauduleuses',
            'content' => 'Un domaine ou service frauduleux désigne tout nom de domaine, site web ou ressource hébergée utilisé pour commettre une fraude, une tromperie ou toute forme de cybercriminalité. Cela comprend, sans s’y limiter :',
            'items' => array(
                'Les sites d’usurpation d’identité, d’imitation de personnes ou d’ingénierie sociale.',
                'Les activités liées au cyberterrorisme ou les contenus promouvant la violence et l’extrémisme.',
                'La contrefaçon, la falsification de documents ou les services de faux justificatifs.',
                'Les escroqueries aux frais anticipés, l’hameçonnage et les opérations d’usurpation.',
                'Les escroqueries financières en ligne, les systèmes de Ponzi et les plateformes d’investissement frauduleux.',
                'Les domaines conçus pour imiter des banques, des plateformes fintech, des prestataires de paiement ou des établissements financiers reconnus.',
                'La distribution de logiciels malveillants, rançongiciels, logiciels espions ou d’infrastructures de commande de botnets.',
                'Les marchés illégaux, le commerce de données volées ou le trafic de biens et services interdits.',
            ),
        ),
        array(
            'id' => 'monitoring',
            'title' => '3. Surveillance et détection',
            'content' => 'CloudHost247 Inc. emploie une approche à plusieurs niveaux pour surveiller et détecter les activités suspectes sur notre infrastructure :',
            'items' => array(
                '<strong>Surveillance du réseau :</strong> Nos systèmes analysent en continu le trafic réseau pour repérer des schémas anormaux, notamment des connexions sortantes inhabituelles, des transferts de données volumineux et des associations avec des IP malveillantes connues.',
                '<strong>Examens de l’équipe chargée des abus :</strong> L’équipe chargée des abus de CloudHost247 Inc. réalise une surveillance et des examens de sécurité réguliers des domaines et services hébergés sur la Plateforme pour identifier les activités suspectes ou potentiellement frauduleuses.',
                '<strong>Analyse comportementale :</strong> Nous surveillons le comportement des comptes pour repérer des indicateurs de compromission, tels que des lieux de connexion inattendus, des tentatives de force brute et des schémas de réutilisation automatisée d’identifiants volés.',
                '<strong>Renseignement tiers :</strong> Nous nous abonnons à des flux reconnus de renseignement sur les menaces, à des listes de blocage et à des avis de sécurité pour identifier les domaines frauduleux connus et les nouveaux vecteurs d’attaque.',
                '<strong>Vérification des titulaires :</strong> Les nouveaux enregistrements et les commandes à haut risque font l’objet de vérifications automatiques et manuelles pour confirmer leur légitimité avant l’activation complète du service.',
            ),
        ),
        array(
            'id' => 'data-collection',
            'title' => '4. Collecte de données pour la détection',
            'content' => 'Pour détecter et prévenir efficacement la cybercriminalité, CloudHost247 Inc. peut collecter et analyser les catégories de données suivantes :',
            'items' => array(
                '<strong>Journaux de serveur et d’accès :</strong> Journaux de requêtes HTTP, journaux d’authentification, enregistrements d’accès FTP/SFTP et historique de connexion au panneau de contrôle.',
                '<strong>Données réseau :</strong> Adresses IP, données de géolocalisation, informations ASN, horodatages des connexions et schémas d’utilisation de la bande passante.',
                '<strong>Métadonnées de compte :</strong> Informations d’enregistrement, données de vérification du moyen de paiement, coordonnées et historique des commandes.',
                '<strong>Empreintes de contenu :</strong> Empreintes cryptographiques des fichiers hébergés, comparées aux bases de contenus malveillants connus pour détecter la distribution de logiciels malveillants sans accéder directement au contenu des fichiers.',
                '<strong>Enregistrements de communication :</strong> Tickets d’assistance, signalements d’abus et correspondance relative aux incidents de sécurité.',
            ),
        ),
        array(
            'id' => 'automated',
            'title' => '5. Systèmes de détection automatique',
            'content' => 'CloudHost247 Inc. utilise des outils automatiques et des systèmes d’intelligence artificielle pour renforcer ses capacités de détection des menaces :',
            'items' => array(
                '<strong>Moteurs de détection d’anomalies :</strong> Des systèmes fondés sur l’IA analysent les références de trafic et signalent les écarts pouvant indiquer une compromission, une activité DDoS ou une utilisation non autorisée de ressources.',
                '<strong>Analyse de logiciels malveillants :</strong> Des services automatiques d’analyse de fichiers inspectent régulièrement les contenus hébergés à la recherche de signatures de logiciels malveillants connus, de scripts shell et de motifs de code suspects.',
                '<strong>Détection d’hameçonnage :</strong> Des modèles d’apprentissage automatique évaluent les noms de domaine, le contenu des pages et la similarité visuelle pour détecter les sites d’hameçonnage imitant des marques de confiance.',
                '<strong>Filtres antispam et anti-abus :</strong> Le trafic de messagerie est surveillé par filtrage automatique pour détecter le spam sortant, l’usurpation et les campagnes de fraude par e-mail.',
                '<strong>Limitation de débit et blocage :</strong> Les systèmes automatiques appliquent des limites de débit et peuvent bloquer temporairement les IP présentant un comportement de force brute, d’analyse ou de déni de service.',
            ),
        ),
        array(
            'id' => 'user-responsibilities',
            'title' => '6. Responsabilités des utilisateurs',
            'content' => 'Tous les clients et utilisateurs des services de CloudHost247 Inc. doivent respecter la loi et notre politique d’utilisation acceptable. Vous ne devez notamment PAS :',
            'items' => array(
                'Utiliser nos services pour héberger, distribuer, promouvoir ou faciliter toute activité illégale, frauduleuse ou nuisible.',
                'Enregistrer des domaines ou créer des services conçus pour tromper des tiers, usurper leur identité ou les escroquer.',
                'Téléverser, transmettre ou stocker des logiciels malveillants, kits d’exploitation, identifiants volés ou contenus illégaux.',
                'Lancer des attaques par déni de service, des analyses de ports ou des tentatives d’intrusion non autorisées, ou y participer.',
                'Envoyer des e-mails de masse non sollicités (spam), des messages d’hameçonnage ou pratiquer l’usurpation d’e-mails.',
                'Tenter de contourner notre surveillance, nos contrôles de sécurité ou nos mécanismes de détection des abus.',
                'Fournir des informations d’identité fausses, trompeuses ou volées lors de l’enregistrement du compte.',
            ),
        ),
        array(
            'id' => 'reporting',
            'title' => '7. Signalement de cybercriminalité et d’activités suspectes',
            'content' => 'Si un particulier ou une organisation soupçonne qu’un domaine ou service hébergé chez CloudHost247 Inc. est utilisé à des fins frauduleuses ou criminelles, veuillez le signaler rapidement :',
            'items' => array(
                '<strong>E-mail :</strong> Envoyez un signalement détaillé à <a href="mailto:support@cloudhost247.com">support@cloudhost247.com</a> ou à <a href="mailto:abuse@cloudhost247.com">abuse@cloudhost247.com</a>.',
                '<strong>Objet :</strong> Utilisez « Cybercrime Report » ou « Abuse Complaint » pour un acheminement plus rapide.',
                '<strong>Informations requises :</strong> Votre nom et vos coordonnées ; le nom de domaine, l’adresse IP ou l’identifiant de service concerné ; une description de l’activité suspectée ; des preuves ou captures d’écran, si disponibles ; et les horodatages ou identifiants de transaction pertinents.',
                '<strong>Confidentialité :</strong> L’identité du déclarant est tenue confidentielle dans les limites autorisées par la loi. Nous ne divulguons pas les coordonnées du plaignant à la partie mise en cause, sauf obligation légale.',
            ),
        ),
        array(
            'id' => 'investigation',
            'title' => '8. Procédure d’enquête',
            'content' => 'À réception d’une plainte ou à la détection d’une activité suspecte, l’équipe chargée des abus :',
            'items' => array(
                'Examine et évalue le signalement ou l’alerte sous 24–48 heures.',
                'Informe si nécessaire le titulaire de l’enregistrement ou du compte concerné et demande des éclaircissements ou des pièces justificatives.',
                'Demande des documents de vérification (immatriculation d’entreprise, pièces d’identité ou preuve d’usage légitime) lorsque la finalité du domaine est incertaine.',
                'Si le titulaire fournit des informations valides et vérifiables confirmant un usage légitime, le domaine ou le service reste actif.',
                'Si le titulaire ne répond pas dans les 7 jours ouvrés ou ne peut prouver un usage légitime, la Société se réserve le droit de suspendre immédiatement le domaine ou de résilier les services d’hébergement associés.',
            ),
        ),
        array(
            'id' => 'enforcement',
            'title' => '9. Mesures d’application',
            'content' => 'CloudHost247 Inc. se réserve le droit de prendre les mesures suivantes lorsque la cybercriminalité, la fraude ou les abus sont confirmés :',
            'items' => array(
                '<strong>Suspension temporaire :</strong> Suspension immédiate du domaine, du site ou du service pour prévenir la poursuite du préjudice pendant l’enquête.',
                '<strong>Résiliation définitive :</strong> Résiliation complète des services d’hébergement, de la résolution du domaine et de l’accès au compte en cas d’infractions confirmées.',
                '<strong>Conservation des données :</strong> Conservation des journaux et contenus pertinents pendant une durée raisonnable pour appuyer les enquêtes des autorités.',
                '<strong>Signalement aux autorités :</strong> Les cas graves peuvent, à notre discrétion, être transmis aux autorités compétentes locales, nationales ou internationales.',
                '<strong>Restrictions réseau :</strong> Les adresses ou plages IP associées à des activités malveillantes peuvent être bloquées à la périphérie du réseau pour protéger les autres clients.',
                '<strong>Absence de remboursement :</strong> Les comptes résiliés pour cybercriminalité ou abus perdent tout droit au remboursement des services prépayés.',
            ),
        ),
        array(
            'id' => 'privacy',
            'title' => '10. Considérations relatives à la confidentialité',
            'content' => 'Toutes les activités de surveillance et de détection sont menées dans le respect de la confidentialité des utilisateurs et des lois applicables de protection des données :',
            'items' => array(
                'La surveillance est strictement limitée à la détection des menaces de sécurité, de la fraude et des violations de notre politique d’utilisation acceptable.',
                'Nous n’accédons pas aux données privées, e-mails ou bases de données des clients sans fondement juridique valable, tel qu’une décision de justice, ou leur consentement explicite à des fins d’assistance.',
                'Les données collectées à des fins de sécurité ne sont conservées que pendant la durée nécessaire à la détection, à l’enquête et aux obligations légales éventuelles.',
                'Les coordonnées des titulaires ne sont divulguées que si la loi l’exige, au titre d’une décision judiciaire valable ou d’une demande officielle des autorités.',
                'Nous respectons les réglementations applicables de protection des données, notamment le GDPR, le CCPA et les lois nationales pertinentes sur la confidentialité.',
            ),
        ),
        array(
            'id' => 'updates',
            'title' => '11. Mises à jour de la politique',
            'content' => 'CloudHost247 Inc. se réserve le droit de mettre à jour ou de modifier cette politique de détection de la cybercriminalité à tout moment pour refléter les évolutions technologiques, les menaces, les exigences légales ou les pratiques opérationnelles. Les mises à jour seront publiées sur cette page avec une date d’entrée en vigueur révisée. La poursuite de l’utilisation de nos services après les changements vaut acceptation de la politique mise à jour. Nous invitons tous les utilisateurs et parties prenantes à consulter régulièrement cette politique.',
        ),
    ),
    'contact' => array(
        'title' => 'Coordonnées',
        'content' => 'Pour signaler un abus, une fraude ou une activité suspecte impliquant un service hébergé chez CloudHost247 Inc., ou pour toute question concernant cette politique, veuillez contacter notre équipe chargée des abus et de la sécurité :',
        'portal' => 'portail d’assistance',
    ),
);
