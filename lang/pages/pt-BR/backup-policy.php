<?php
/** backup-policy — pt-BR. English remains inline; legal review required before release. */
return array(
    'hero' => array(
        'title' => 'Política de backup',
        'subtitle' => 'Última atualização: 26 de abril de 2026',
    ),
    'introduction' => array(
        'content' => 'Esta política de backup descreve as práticas e responsabilidades de backup dos serviços fornecidos pela CloudHost247 Inc. Nosso objetivo é proteger os dados dos clientes por meio de procedimentos sistemáticos de backup, definindo claramente os limites de responsabilidade do provedor e as obrigações do usuário. Leia esta política com atenção para entender como seus dados são protegidos e quais medidas você deve tomar para manter suas próprias cópias independentes.',
    ),
    'sections' => array(
        array(
            'id' => 'responsibility',
            'title' => '1. Responsabilidade pelo backup',
            'content' => 'A responsabilidade pelo backup é compartilhada entre a CloudHost247 Inc. e nossos clientes:',
            'items' => array(
                '<strong>Responsabilidade do provedor:</strong> A CloudHost247 Inc. realiza backups automáticos dos ambientes de servidores de hospedagem como cortesia e medida de recuperação de desastres. Esses backups visam restaurar a continuidade do serviço em caso de falha de hardware, corrupção do sistema ou eventos catastróficos de infraestrutura.',
                '<strong>Responsabilidade do usuário:</strong> Os clientes são os responsáveis finais por manter seus próprios backups independentes de todos os arquivos de sites, bancos de dados, e-mails e dados de aplicativos hospedados em nossa infraestrutura. Nossos backups automáticos não devem ser usados como estratégia única ou principal de proteção de dados.',
                '<strong>Escopo:</strong> Os backups gerenciados pelo provedor abrangem pontos de restauração no nível do servidor e não incluem recuperação de arquivos individuais, restauração de e-mails de caixas excluídas ou recuperação granular de tabelas de bancos de dados, salvo indicação expressa no seu plano de serviço.',
            ),
        ),
        array(
            'id' => 'frequency',
            'title' => '2. Frequência dos backups',
            'content' => 'Os backups automáticos são realizados conforme o seguinte cronograma:',
            'items' => array(
                '<strong>Hospedagem compartilhada e de revenda:</strong> Backups incrementais diários com consolidação completa semanal todo domingo às 02:00 UTC.',
                '<strong>Hospedagem VPS:</strong> Snapshots completos semanais capturados todo sábado às 03:00 UTC. Clientes VPS podem configurar snapshots mais frequentes pelo painel de controle por um custo adicional.',
                '<strong>Servidores dedicados:</strong> A frequência depende do nível de suporte gerenciado escolhido na compra. Planos gerenciados padrão incluem backups semanais; planos premium incluem backups diários. Servidores dedicados autogerenciados não incluem backups do provedor, salvo aquisição de um complemento de backup separado.',
                '<strong>Bancos de dados:</strong> Bancos MySQL/MariaDB em hospedagem compartilhada e de revenda estão incluídos no ciclo diário de backup. Serviços independentes de banco de dados são copiados conforme o cronograma do nível de hospedagem.',
            ),
        ),
        array(
            'id' => 'retention',
            'title' => '3. Retenção de dados',
            'content' => 'Os prazos de retenção de backups variam conforme o tipo de serviço:',
            'items' => array(
                '<strong>Hospedagem compartilhada e de revenda:</strong> Backups diários mantidos por 7 dias; backups semanais mantidos por 30 dias.',
                '<strong>Snapshots VPS:</strong> Snapshots semanais mantidos por 14 dias. Espaços adicionais de snapshot podem ser adquiridos para ampliar a retenção.',
                '<strong>Backups de servidores dedicados:</strong> Backups gerenciados padrão mantidos por 14 dias; backups gerenciados premium por 30 dias.',
                '<strong>Contas expiradas ou canceladas:</strong> Todos os backups associados a uma conta cancelada, encerrada ou expirada são excluídos permanentemente em até 72 horas após a desativação do serviço. Não são mantidos backups de contas inativas.',
                '<strong>Dados sobrescritos:</strong> Os backups funcionam em um ciclo contínuo de rotação. Após a conclusão de um ciclo e a substituição de pontos antigos por novos dados, os dados anteriores não podem ser recuperados.',
            ),
        ),
        array(
            'id' => 'restoration',
            'title' => '4. Política de restauração',
            'content' => 'Se você precisar de restauração de dados, aplicam-se o processo e as limitações a seguir:',
            'items' => array(
                '<strong>Pedidos de restauração:</strong> Envie um ticket de suporte pelo portal do cliente indicando o serviço, a data aproximada dos dados que deseja restaurar e o motivo do pedido.',
                '<strong>Tempo de processamento:</strong> Pedidos padrão são processados em 24–48 horas durante dias úteis. Pedidos de restauração emergencial podem gerar taxas adicionais.',
                '<strong>Escopo da restauração:</strong> Os backups do provedor são restaurados no nível da conta ou do servidor. Não realizamos restaurações seletivas arquivo por arquivo, por pasta ou de e-mails individuais a partir de backups do sistema. Usuários que precisem de recuperação granular devem manter seus próprios backups independentes.',
                '<strong>Custo:</strong> Restaurações dos ciclos padrão são incluídas sem cobrança para serviços gerenciados. Restaurações fora dos prazos normais de retenção ou para serviços autogerenciados sem complemento de backup ativo podem estar sujeitas a taxas horárias de trabalho técnico.',
                '<strong>Sem garantia:</strong> Embora façamos todos os esforços razoáveis para manter a integridade dos backups, não podemos garantir a disponibilidade, completude ou utilidade de um ponto específico de restauração no momento do pedido.',
            ),
        ),
        array(
            'id' => 'limitations',
            'title' => '5. Limitações e responsabilidade',
            'content' => 'A CloudHost247 Inc. fornece backups com base nos melhores esforços. Aplicam-se as seguintes limitações:',
            'items' => array(
                'Nossos sistemas de backup foram projetados para recuperação de desastres, não para substituir as estratégias de proteção de dados dos clientes.',
                'Não somos responsáveis por perda de dados, interrupção de negócios, perda de receita ou danos consequentes decorrentes de falhas de backup, backups incompletos, restauração atrasada ou indisponibilidade de backups.',
                'Os backups podem não capturar dados corrompidos antes da janela de backup, dados excluídos pelo cliente ou dados modificados entre os intervalos de backup.',
                'Não fazemos backup de conteúdo que viole nossa política de uso aceitável, incluindo malware, material pirateado ou conteúdo ilegal. Esse conteúdo pode ser excluído dos backups sem aviso.',
                'A responsabilidade total máxima por qualquer reclamação relacionada ao backup está limitada à taxa mensal proporcional do serviço afetado.',
            ),
        ),
        array(
            'id' => 'user-responsibility',
            'title' => '6. Responsabilidade do usuário',
            'content' => 'Todos os clientes são fortemente incentivados e devem:',
            'items' => array(
                'Manter backups regulares e independentes de todos os dados críticos armazenados em nossos servidores, incluindo arquivos de sites, bancos de dados e conteúdo de e-mail.',
                'Usar as ferramentas disponíveis do painel de controle (como cPanel Backup Wizard, exportações do phpMyAdmin ou downloads FTP) para criar e baixar cópias pessoais de backup.',
                'Testar periodicamente os procedimentos de restauração para garantir que seus backups independentes sejam válidos e utilizáveis.',
                'Armazenar pelo menos uma cópia dos backups críticos em um local externo ou geograficamente separado.',
                'Entender que depender exclusivamente dos backups gerenciados pelo provedor coloca seus dados em risco e não é recomendado para aplicações essenciais ao negócio.',
            ),
        ),
        array(
            'id' => 'updates',
            'title' => '7. Atualizações da política',
            'content' => 'A CloudHost247 Inc. reserva-se o direito de atualizar ou modificar esta política de backup a qualquer momento. As mudanças podem incluir ajustes de frequência, prazos de retenção, procedimentos de restauração ou escopo de cobertura. As atualizações serão publicadas nesta página com uma data de vigência revisada. O uso continuado dos nossos serviços após alterações da política constitui aceitação dos termos atualizados. Recomendamos que os clientes revisem esta política periodicamente.',
        ),
    ),
    'contact' => array(
        'title' => 'Fale conosco',
        'content' => 'Se você tiver dúvidas sobre nossa política de backup ou precisar de ajuda com a restauração de dados, entre em contato com nossa equipe de suporte:',
        'portal' => 'portal de suporte',
    ),
);
