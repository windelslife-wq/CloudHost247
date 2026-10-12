<?php
/** backup-policy — fr. English remains inline; legal review required before release. */
return array(
    'hero' => array(
        'title' => 'Politique de sauvegarde',
        'subtitle' => 'Dernière mise à jour : 26 avril 2026',
    ),
    'introduction' => array(
        'content' => 'Cette politique de sauvegarde décrit les pratiques et les responsabilités de sauvegarde relatives aux services fournis par CloudHost247 Inc. Notre objectif est de protéger les données des clients par des procédures de sauvegarde systématiques, tout en définissant clairement les limites de la responsabilité du fournisseur et les obligations des utilisateurs. Veuillez consulter attentivement cette politique pour comprendre comment vos données sont protégées et les mesures à prendre pour conserver vos propres copies indépendantes.',
    ),
    'sections' => array(
        array(
            'id' => 'responsibility',
            'title' => '1. Responsabilité de sauvegarde',
            'content' => 'La responsabilité de sauvegarde est partagée entre CloudHost247 Inc. et nos clients :',
            'items' => array(
                '<strong>Responsabilité du fournisseur :</strong> CloudHost247 Inc. effectue des sauvegardes automatiques des environnements de serveurs d’hébergement à titre gracieux et comme mesure de reprise après sinistre. Ces sauvegardes visent à rétablir la continuité du service en cas de panne matérielle, de corruption au niveau du système ou d’événements catastrophiques affectant l’infrastructure.',
                '<strong>Responsabilité de l’utilisateur :</strong> Les clients sont en dernier ressort responsables de conserver leurs propres sauvegardes indépendantes de tous les fichiers de site, bases de données, e-mails et données d’application hébergés sur notre infrastructure. Nos sauvegardes automatiques ne doivent pas constituer votre stratégie unique ou principale de protection des données.',
                '<strong>Périmètre :</strong> Les sauvegardes gérées par le fournisseur couvrent les points de restauration au niveau du serveur et ne s’étendent pas à la récupération de fichiers individuels, à la restauration d’e-mails de boîtes supprimées ou à la récupération détaillée de tables de base de données, sauf indication explicite dans votre formule de service.',
            ),
        ),
        array(
            'id' => 'frequency',
            'title' => '2. Fréquence des sauvegardes',
            'content' => 'Les sauvegardes automatiques sont effectuées selon le calendrier suivant :',
            'items' => array(
                '<strong>Hébergement mutualisé et revendeur :</strong> Sauvegardes incrémentales quotidiennes avec consolidation en sauvegarde complète hebdomadaire chaque dimanche à 02:00 UTC.',
                '<strong>Hébergement VPS :</strong> Instantanés complets hebdomadaires réalisés chaque samedi à 03:00 UTC. Les clients VPS peuvent configurer des instantanés plus fréquents via le panneau de contrôle, moyennant un supplément.',
                '<strong>Serveurs dédiés :</strong> La fréquence des sauvegardes dépend du niveau d’assistance gérée choisi lors de l’achat. Les formules gérées standard comprennent des sauvegardes hebdomadaires ; les formules gérées premium comprennent des sauvegardes quotidiennes. Les serveurs dédiés autogérés ne comprennent pas de sauvegardes du fournisseur, sauf souscription à une option de sauvegarde séparée.',
                '<strong>Bases de données :</strong> Les bases MySQL/MariaDB sur l’hébergement mutualisé et revendeur sont incluses dans le cycle quotidien de sauvegarde. Les services de base de données autonomes sont sauvegardés selon le calendrier de leur niveau d’hébergement.',
            ),
        ),
        array(
            'id' => 'retention',
            'title' => '3. Conservation des données',
            'content' => 'Les durées de conservation des sauvegardes varient selon le type de service :',
            'items' => array(
                '<strong>Hébergement mutualisé et revendeur :</strong> Sauvegardes quotidiennes conservées 7 jours ; sauvegardes hebdomadaires conservées 30 jours.',
                '<strong>Instantanés VPS :</strong> Instantanés hebdomadaires conservés 14 jours. Des emplacements d’instantanés supplémentaires peuvent être achetés pour prolonger la conservation.',
                '<strong>Sauvegardes de serveurs dédiés :</strong> Sauvegardes gérées standard conservées 14 jours ; sauvegardes gérées premium conservées 30 jours.',
                '<strong>Comptes expirés ou résiliés :</strong> Toutes les sauvegardes associées à un compte résilié, fermé ou expiré sont définitivement supprimées dans les 72 heures suivant la désactivation du service. Aucune sauvegarde n’est conservée pour les comptes inactifs.',
                '<strong>Données écrasées :</strong> Les sauvegardes suivent un cycle de rotation continu. Une fois un cycle de sauvegarde terminé et les anciens points de restauration écrasés par de nouvelles données, les données précédentes ne peuvent plus être récupérées.',
            ),
        ),
        array(
            'id' => 'restoration',
            'title' => '4. Politique de restauration',
            'content' => 'Si vous avez besoin d’une restauration des données, la procédure et les limites suivantes s’appliquent :',
            'items' => array(
                '<strong>Demandes de restauration :</strong> Soumettez un ticket d’assistance via le portail client en précisant le service, la date approximative des données à restaurer et le motif de la demande.',
                '<strong>Délai de traitement :</strong> Les demandes de restauration standard sont traitées sous 24–48 heures pendant les jours ouvrés. Les demandes de restauration d’urgence peuvent entraîner des frais supplémentaires.',
                '<strong>Périmètre de restauration :</strong> Les sauvegardes du fournisseur sont restaurées au niveau du compte ou du serveur. Nous n’effectuons pas de restauration sélective fichier par fichier, au niveau d’un dossier ou d’e-mails individuels à partir des sauvegardes système. Les utilisateurs ayant besoin d’une récupération détaillée doivent conserver leurs propres sauvegardes indépendantes.',
                '<strong>Coût :</strong> Les restaurations à partir des cycles de sauvegarde standard sont incluses sans frais pour les services gérés. Les restaurations hors des durées normales de conservation ou pour les services autogérés sans option de sauvegarde active peuvent être soumises à des frais de main-d’œuvre technique horaires.',
                '<strong>Absence de garantie :</strong> Bien que nous fassions tous les efforts raisonnables pour préserver l’intégrité des sauvegardes, nous ne pouvons garantir la disponibilité, l’exhaustivité ou l’exploitabilité d’un point de restauration particulier au moment de la demande.',
            ),
        ),
        array(
            'id' => 'limitations',
            'title' => '5. Limites et responsabilité',
            'content' => 'CloudHost247 Inc. fournit des sauvegardes selon une obligation de moyens. Les limites suivantes s’appliquent :',
            'items' => array(
                'Nos systèmes de sauvegarde sont conçus pour la reprise après sinistre et ne remplacent pas les stratégies de protection des données propres aux clients.',
                'Nous ne sommes pas responsables des pertes de données, interruptions d’activité, pertes de revenus ou dommages indirects résultant d’un échec de sauvegarde, de sauvegardes incomplètes, d’une restauration retardée ou de l’indisponibilité des sauvegardes.',
                'Les sauvegardes peuvent ne pas capturer les données corrompues avant la fenêtre de sauvegarde, les données supprimées par le client ou les données modifiées entre les intervalles de sauvegarde.',
                'Nous ne sauvegardons pas les contenus qui enfreignent notre politique d’utilisation acceptable, notamment les logiciels malveillants, les contenus piratés ou les contenus illégaux. Ces contenus peuvent être exclus des sauvegardes sans préavis.',
                'La responsabilité totale maximale pour toute réclamation liée aux sauvegardes est limitée aux frais mensuels de service calculés au prorata pour le service concerné.',
            ),
        ),
        array(
            'id' => 'user-responsibility',
            'title' => '6. Responsabilité de l’utilisateur',
            'content' => 'Tous les clients sont vivement encouragés et tenus de :',
            'items' => array(
                'Conserver des sauvegardes régulières et indépendantes de toutes les données critiques stockées sur nos serveurs, notamment les fichiers de site, les bases de données et les e-mails.',
                'Utiliser les outils disponibles du panneau de contrôle (tels que cPanel Backup Wizard, les exports phpMyAdmin ou les téléchargements FTP) pour créer et télécharger leurs propres copies de sauvegarde.',
                'Tester régulièrement les procédures de restauration pour s’assurer que leurs sauvegardes indépendantes sont valides et exploitables.',
                'Conserver au moins une copie des sauvegardes critiques dans un emplacement hors site ou géographiquement séparé.',
                'Comprendre que se fier uniquement aux sauvegardes gérées par le fournisseur met leurs données en danger et n’est pas recommandé pour les applications essentielles à l’activité.',
            ),
        ),
        array(
            'id' => 'updates',
            'title' => '7. Mises à jour de la politique',
            'content' => 'CloudHost247 Inc. se réserve le droit de mettre à jour ou de modifier cette politique de sauvegarde à tout moment. Les changements peuvent inclure des ajustements de la fréquence des sauvegardes, des durées de conservation, des procédures de restauration ou du périmètre de couverture. Les mises à jour seront publiées sur cette page avec une date d’entrée en vigueur révisée. La poursuite de l’utilisation de nos services après les modifications de la politique vaut acceptation des conditions mises à jour. Nous invitons les clients à consulter régulièrement cette politique.',
        ),
    ),
    'contact' => array(
        'title' => 'Contactez-nous',
        'content' => 'Si vous avez des questions concernant notre politique de sauvegarde ou avez besoin d’aide pour restaurer des données, veuillez contacter notre équipe d’assistance :',
        'portal' => 'portail d’assistance',
    ),
);
