# Contract Hours

> [!WARNING]
> **Status: Em Desenvolvimento (Beta / Pré-homologação)**
> Este plugin teve a arquitetura, a segurança e os fluxos validados por revisão de código, auditoria de segurança e testes unitários automatizados em CI (`PHP 7.4–8.3` com mocks de GLPI 10/11/12), mas **ainda não foi testado em uma instância GLPI ao vivo**. Valide em ambiente de homologação antes de usar em produção e reporte eventuais ajustes via Issues.

Plugin para o GLPI que gerencia bancos de horas vinculados a contratos (`Contract`), com débito e estorno automático a partir de tarefas de chamados (`TicketTask`), razão contábil auditável em minutos inteiros e painel de acompanhamento.

| Versão do GLPI | PHP mínimo | Suporte |
|---|---|---|
| **12.0.x** | 8.3+ | ✅ |
| **11.0.x** | 8.2+ | ✅ |
| **10.0.x** | 7.4+ | ✅ |

---

## Funcionalidades

- **Contabilidade em minutos inteiros:** armazena franquia (`contracted_minutes`) e movimentações (`minutes_delta`) em minutos inteiros no banco de dados, evitando imprecisões de ponto flutuante e exibindo saldos em `HH:MM` (incluindo saldos negativos como `-01:30`).
- **Resolução automática de contrato em 4 níveis para cada chamado:**
  1. Vínculo explícito registrado em `glpi_plugin_contracthours_ticket_links`.
  2. Contrato ativo associado diretamente ao chamado em `glpi_contracts_items` (`itemtype = 'Ticket'`).
  3. Contrato ativo associado via ativo vinculado ao chamado em `glpi_items_tickets`.
  4. Contrato padrão configurado para a entidade do chamado ou ancestral com herança recursiva.
- **Débito e estorno automático em `TicketTask`:**
  - `item_add`: debita `round(actiontime / 60)` minutos quando o tempo for maior que zero e houver pool ativo.
  - `item_update`: calcula a diferença incremental em relação ao total já debitado pela tarefa e registra apenas o ajuste (`debit` ou `refund`), inclusive caso o contrato do chamado tenha sido alterado.
  - `item_delete` / `item_purge`: estorna integralmente o saldo líquido debitado pela tarefa de forma idempotente.
- **Bloqueio opcional de estouro (`block_on_overrun`):** impede interativamente a criação ou aumento de tarefas que ultrapassariam o saldo disponível do contrato (sem interferir em rotinas CLI/cron).
- **Alertas de limiar configuráveis:** exibe aviso na sessão quando o consumo atinge `alert_threshold_pct` (padrão `80%`) ou entra em saldo negativo (`< 0`), disparando uma vez por transição.
- **Abas dedicadas em `Contract` e `Ticket`:** permitem configurar franquia, valor hora, ciclo, realizar ajustes manuais de crédito/débito, definir contrato explícito no chamado e auditar o extrato do razão.
- **Painel administrativo (`Configurar › Plugins › Contract Hours`):** apresenta resumo dos pools ativos com barras de progresso e badges de status, além do mapeamento de contrato padrão por entidade.
- **Limpeza automática:** remove vínculos, pools e lançamentos órfãos no expurgo de `Ticket` ou `Contract` (`item_purge`) e limpa tabelas e configurações na desinstalação.

---

## Instalação

1. Baixe o arquivo **`contracthours-x.y.z.zip`** na página de [Releases](https://github.com/GustavoMS0/GLPI-ContractHours/releases/latest).
2. Extraia dentro do diretório de plugins do GLPI:
   ```
   glpi/plugins/contracthours/setup.php
   ```
3. No GLPI, acesse **Configurar › Plugins**, clique em **Instalar** e depois em **Ativar** em **Contract Hours**.

### Pelo terminal (Linux)

```bash
cd /var/www/glpi/plugins
VERSAO=$(curl -fsSL https://api.github.com/repos/GustavoMS0/GLPI-ContractHours/releases/latest | grep -oP '"tag_name": "v\K[^"]+')
sudo curl -fsSL -o contracthours.zip "https://github.com/GustavoMS0/GLPI-ContractHours/releases/download/v$VERSAO/contracthours-$VERSAO.zip"
sudo unzip -o contracthours.zip && rm contracthours.zip
sudo chown -R www-data:www-data contracthours

sudo -u www-data php ../bin/console plugin:install --username=glpi contracthours
sudo -u www-data php ../bin/console plugin:activate contracthours
```

---

## Configuração

1. Acesse **Administração › Contratos**, abra um contrato e entre na aba **Banco de Horas** para ativar o pool, informar a franquia em horas/minutos, limiar de alerta, bloqueio de estouro, rollover, ciclo e valor hora.
2. Em **Configurar › Plugins › Contract Hours**, configure o limiar padrão sugerido, o contrato padrão por entidade (com opção recursiva) e a visibilidade da aba no autoatendimento (Self-Service).

---

## Testes

O projeto inclui testes unitários independentes em `tests/`:

```bash
php tests/run.php
```

---

## Licença

Copyright (C) 2026 by G. Martins.

Distribuído sob os termos da licença **GPLv3+** (`GNU General Public License v3 or later`). Consulte [LICENSE](LICENSE).
