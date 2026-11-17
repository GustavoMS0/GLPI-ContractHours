# Changelog

## [1.0.0] - 2026-11-17

### Adicionado
- Versão inicial do plugin **Contract Hours** com compatibilidade única para GLPI 10.0.0 a 12.0.99 e PHP 7.4+.
- Esquema dedicado com as tabelas `glpi_plugin_contracthours_pools`, `glpi_plugin_contracthours_ledger` e `glpi_plugin_contracthours_ticket_links`.
- Contabilidade em minutos inteiros (`contracted_minutes` e `minutes_delta` com sinal) e formatação `HH:MM` com suporte a saldos negativos.
- Resolução automática de contrato em 4 níveis para chamados: vínculo explícito, `glpi_contracts_items` no chamado, `glpi_contracts_items` via ativo em `glpi_items_tickets` e contrato padrão por entidade com herança recursiva.
- Hooks em `TicketTask` (`pre_item_add`, `pre_item_update`, `item_add`, `item_update`, `item_delete`, `item_purge`) para débito, delta incremental, estorno e bloqueio opcional de estouro (`block_on_overrun`).
- Alertas de transição ao atingir `alert_threshold_pct` (padrão `80%`) ou saldo negativo.
- Abas dedicadas em `Contract` e `Ticket` com extratos do razão, ajustes manuais e seleção explícita de contrato.
- Painel administrativo em `front/config.form.php` com barras de progresso, badges de status e mapeamento de contrato padrão por entidade.
- Limpeza automática em `item_purge` de `Contract` e `Ticket` e remoção completa de esquema e configurações na desinstalação.
- Suíte de testes unitários automatizados em `tests/run.php` cobrindo resolução em 4 níveis, matemática de tarefas, bloqueio de estouro, alertas, ajustes manuais, expurgo e compatibilidade GLPI 10/11/12.
