<?php
/** cybercrime-policy — pt-BR. English remains inline; legal review required before release. */
return array(
    'hero' => array(
        'title' => 'Política de detecção de crimes cibernéticos',
        'subtitle' => 'Última atualização: 26 de abril de 2026',
    ),
    'introduction' => array(
        'content' => 'A CloudHost247 Inc. (“a Empresa”) tem o compromisso de garantir que todos os domínios registrados, hospedados ou gerenciados em nossa plataforma não sejam usados para atividades ilegais, fraudulentas ou maliciosas. Esta política de detecção de crimes cibernéticos explica as medidas preventivas e de detecção que usamos para identificar atividades suspeitas ou fraudulentas, os procedimentos de tratamento de denúncias de crimes cibernéticos ou abuso e como pessoas e organizações podem relatar questões relativas a serviços hospedados em nossa plataforma.',
    ),
    'sections' => array(
        array(
            'id' => 'scope',
            'title' => '1. Escopo',
            'content' => 'Esta política se aplica a:',
            'items' => array(
                'Todos os funcionários, prestadores e agentes da CloudHost247 Inc.',
                'Todos os visitantes, usuários e titulares de contas da Plataforma.',
                'Todos os clientes registrados, registrantes e proprietários de domínios.',
                'Todos os denunciantes, órgãos de aplicação da lei e terceiros que apresentem relatos.',
            ),
        ),
        array(
            'id' => 'fraud-definition',
            'title' => '2. Definição de atividade fraudulenta',
            'content' => 'Um domínio ou serviço fraudulento é qualquer nome de domínio, site ou recurso hospedado usado para cometer fraude, engano ou qualquer forma de crime cibernético. Isso inclui, entre outros:',
            'items' => array(
                'Sites de roubo de identidade, falsidade de identidade ou engenharia social.',
                'Atividades relacionadas ao ciberterrorismo ou materiais que promovam violência e extremismo.',
                'Falsificação, adulteração de documentos ou serviços de credenciais falsas.',
                'Golpes de pagamento antecipado, phishing e operações de falsificação de origem.',
                'Golpes financeiros on-line, esquemas Ponzi e plataformas de fraude de investimento.',
                'Domínios criados para imitar bancos, plataformas fintech, processadores de pagamentos ou instituições financeiras reconhecidas.',
                'Distribuição de malware, ransomware, spyware ou infraestrutura de comando de botnets.',
                'Mercados ilegais, comércio de dados roubados ou tráfico de bens e serviços proibidos.',
            ),
        ),
        array(
            'id' => 'monitoring',
            'title' => '3. Monitoramento e detecção',
            'content' => 'A CloudHost247 Inc. usa uma abordagem em várias camadas para monitorar e detectar atividades suspeitas em nossa infraestrutura:',
            'items' => array(
                '<strong>Monitoramento de rede:</strong> Nossos sistemas analisam continuamente o tráfego para detectar padrões anormais, incluindo conexões de saída incomuns, transferências de alto volume e associações com IPs maliciosos conhecidos.',
                '<strong>Análises da equipe de abuso:</strong> A equipe de abuso da CloudHost247 Inc. realiza monitoramento e análises de segurança periódicas de domínios e serviços hospedados na Plataforma para identificar atividades suspeitas ou potencialmente fraudulentas.',
                '<strong>Análise comportamental:</strong> Monitoramos o comportamento de contas em busca de sinais de comprometimento, como locais de login inesperados, tentativas de força bruta e padrões de uso automatizado de credenciais roubadas.',
                '<strong>Inteligência de terceiros:</strong> Assinamos fontes respeitáveis de inteligência de ameaças, listas de bloqueio e alertas de segurança para identificar domínios fraudulentos conhecidos e novos vetores de ataque.',
                '<strong>Verificação de registrantes:</strong> Novos registros e pedidos de alto risco passam por verificações automáticas e manuais para confirmar a legitimidade antes da ativação completa do serviço.',
            ),
        ),
        array(
            'id' => 'data-collection',
            'title' => '4. Coleta de dados para detecção',
            'content' => 'Para detectar e prevenir crimes cibernéticos de forma eficaz, a CloudHost247 Inc. pode coletar e analisar as seguintes categorias de dados:',
            'items' => array(
                '<strong>Logs de servidor e acesso:</strong> Logs de requisições HTTP, logs de autenticação, registros de acesso FTP/SFTP e histórico de login no painel de controle.',
                '<strong>Dados de rede:</strong> Endereços IP, dados de geolocalização, informações ASN, horários de conexão e padrões de uso de largura de banda.',
                '<strong>Metadados da conta:</strong> Dados de registro, verificação da forma de pagamento, informações de contato e histórico de pedidos.',
                '<strong>Hashes de conteúdo:</strong> Hashes criptográficos de arquivos hospedados comparados com bases de conteúdo malicioso conhecido para detectar distribuição de malware sem acessar diretamente o conteúdo dos arquivos.',
                '<strong>Registros de comunicação:</strong> Tickets de suporte, denúncias de abuso e correspondência relacionada a incidentes de segurança.',
            ),
        ),
        array(
            'id' => 'automated',
            'title' => '5. Sistemas automáticos de detecção',
            'content' => 'A CloudHost247 Inc. utiliza ferramentas automáticas e sistemas de inteligência artificial para aprimorar a detecção de ameaças:',
            'items' => array(
                '<strong>Mecanismos de detecção de anomalias:</strong> Sistemas com IA analisam as referências de tráfego e sinalizam desvios que possam indicar comprometimento, atividade DDoS ou uso não autorizado de recursos.',
                '<strong>Verificação de malware:</strong> Serviços automáticos de análise de arquivos inspecionam periodicamente o conteúdo hospedado em busca de assinaturas conhecidas de malware, scripts de shell e padrões de código suspeitos.',
                '<strong>Detecção de phishing:</strong> Modelos de aprendizado de máquina avaliam domínios, conteúdo de páginas e semelhança visual para detectar sites de phishing que imitam marcas confiáveis.',
                '<strong>Filtros de spam e abuso:</strong> O tráfego de e-mail é monitorado por filtragem automática para detectar spam de saída, falsificação de origem e campanhas de fraude por e-mail.',
                '<strong>Limitação de taxa e bloqueio:</strong> Os sistemas automáticos impõem limites de taxa e podem bloquear temporariamente IPs com comportamento de força bruta, varredura ou negação de serviço.',
            ),
        ),
        array(
            'id' => 'user-responsibilities',
            'title' => '6. Responsabilidades do usuário',
            'content' => 'Todos os clientes e usuários dos serviços da CloudHost247 Inc. devem cumprir a lei e nossa política de uso aceitável. Especificamente, você NÃO deve:',
            'items' => array(
                'Usar nossos serviços para hospedar, distribuir, promover ou facilitar atividades ilegais, fraudulentas ou prejudiciais.',
                'Registrar domínios ou criar serviços projetados para enganar, se passar por terceiros ou defraudá-los.',
                'Enviar, transmitir ou armazenar malware, kits de exploração, credenciais roubadas ou conteúdo ilegal.',
                'Iniciar ou participar de ataques de negação de serviço, varreduras de portas ou tentativas de invasão não autorizadas.',
                'Enviar e-mails em massa não solicitados (spam), mensagens de phishing ou falsificar a origem de e-mails.',
                'Tentar contornar nosso monitoramento, controles de segurança ou mecanismos de detecção de abuso.',
                'Fornecer informações de identidade falsas, enganosas ou roubadas no cadastro da conta.',
            ),
        ),
        array(
            'id' => 'reporting',
            'title' => '7. Denúncia de crimes cibernéticos e atividades suspeitas',
            'content' => 'Se uma pessoa ou organização suspeitar que um domínio ou serviço hospedado na CloudHost247 Inc. está sendo usado para fins fraudulentos ou criminosos, informe prontamente:',
            'items' => array(
                '<strong>E-mail:</strong> Envie um relato detalhado para <a href="mailto:support@cloudhost247.com">support@cloudhost247.com</a> ou <a href="mailto:abuse@cloudhost247.com">abuse@cloudhost247.com</a>.',
                '<strong>Assunto:</strong> Use “Cybercrime Report” ou “Abuse Complaint” para um encaminhamento mais rápido.',
                '<strong>Dados necessários:</strong> Seu nome e dados de contato; o domínio, endereço IP ou identificador do serviço em questão; uma descrição da atividade suspeita; provas ou capturas de tela, se disponíveis; e quaisquer horários ou IDs de transação pertinentes.',
                '<strong>Confidencialidade:</strong> A identidade do denunciante é mantida em sigilo na medida permitida por lei. Não divulgamos seus dados de contato à parte acusada, salvo exigência legal.',
            ),
        ),
        array(
            'id' => 'investigation',
            'title' => '8. Processo de investigação',
            'content' => 'Ao receber uma denúncia ou detectar atividade suspeita, a equipe de abuso irá:',
            'items' => array(
                'Analisar e avaliar o relato ou alerta em 24–48 horas.',
                'Notificar o registrante ou titular da conta afetada quando necessário, solicitando esclarecimentos ou documentos de apoio.',
                'Solicitar documentos de verificação (registro comercial, documentos de identidade ou provas de uso legítimo) quando a finalidade do domínio estiver em dúvida.',
                'Se o registrante fornecer informações válidas e verificáveis que confirmem o uso legítimo, o domínio ou serviço permanecerá ativo.',
                'Se o registrante não responder em 7 dias úteis ou não comprovar uso legítimo, a Empresa reserva-se o direito de suspender o domínio imediatamente ou encerrar os serviços de hospedagem associados.',
            ),
        ),
        array(
            'id' => 'enforcement',
            'title' => '9. Medidas de aplicação',
            'content' => 'A CloudHost247 Inc. reserva-se o direito de tomar as seguintes medidas quando crimes cibernéticos, fraude ou abuso forem confirmados:',
            'items' => array(
                '<strong>Suspensão temporária:</strong> Suspensão imediata do domínio, site ou serviço para impedir danos contínuos enquanto a investigação prossegue.',
                '<strong>Encerramento permanente:</strong> Encerramento completo dos serviços de hospedagem, resolução do domínio e acesso à conta para violações confirmadas.',
                '<strong>Preservação de dados:</strong> Retenção de logs e conteúdos relevantes por um período razoável para apoiar investigações das autoridades.',
                '<strong>Comunicação às autoridades:</strong> Casos graves podem, a nosso critério, ser encaminhados às autoridades locais, nacionais ou internacionais competentes.',
                '<strong>Restrições de rede:</strong> Endereços ou faixas de IP associados a atividades maliciosas podem ser bloqueados no perímetro da rede para proteger outros clientes.',
                '<strong>Sem reembolsos:</strong> Contas encerradas por crimes cibernéticos ou abuso perdem qualquer direito de reembolso por serviços pré-pagos.',
            ),
        ),
        array(
            'id' => 'privacy',
            'title' => '10. Considerações de privacidade',
            'content' => 'Todas as atividades de monitoramento e detecção respeitam a privacidade do usuário e cumprem as leis de proteção de dados aplicáveis:',
            'items' => array(
                'O monitoramento se limita estritamente à detecção de ameaças de segurança, fraude e violações da nossa política de uso aceitável.',
                'Não acessamos dados privados, e-mails ou bancos de dados do cliente sem uma base legal válida, como ordem judicial, ou consentimento expresso do cliente para suporte.',
                'Os dados coletados para segurança são mantidos apenas pelo tempo necessário à detecção, investigação e obrigações legais.',
                'Os dados de contato do registrante são divulgados somente quando legalmente exigidos por ordem judicial válida ou solicitação formal das autoridades.',
                'Cumprimos as regulamentações aplicáveis de proteção de dados, incluindo, entre outras, GDPR, CCPA e leis nacionais de privacidade pertinentes.',
            ),
        ),
        array(
            'id' => 'updates',
            'title' => '11. Atualizações da política',
            'content' => 'A CloudHost247 Inc. reserva-se o direito de atualizar ou modificar esta política de detecção de crimes cibernéticos a qualquer momento para refletir mudanças de tecnologia, ameaças, requisitos legais ou práticas operacionais. As atualizações serão publicadas nesta página com uma data de vigência revisada. O uso continuado dos nossos serviços após as mudanças constitui aceitação da política atualizada. Recomendamos que todos os usuários e interessados revisem esta política periodicamente.',
        ),
    ),
    'contact' => array(
        'title' => 'Informações de contato',
        'content' => 'Para denunciar abuso, fraude ou atividades suspeitas relativas a serviços hospedados na CloudHost247 Inc., ou se você tiver dúvidas sobre esta política, entre em contato com nossa equipe de abuso e segurança:',
        'portal' => 'portal de suporte',
    ),
);
