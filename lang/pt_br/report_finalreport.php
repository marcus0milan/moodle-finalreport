<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Relatório final';
$string['finalreport:view'] = 'Ver o relatório final';
$string['privacy:metadata'] = 'O relatório final não armazena dados pessoais.';
$string['downloadpdf'] = 'Baixar relatório para coordenação em PDF';
$string['downloadexcel'] = 'Baixar relatório de controle em Excel';
$string['updatedat'] = 'Atualizado em {$a}';
$string['course'] = 'Curso';
$string['filterby'] = 'Filtrar por';
$string['allstudents'] = 'Todos os alunos';
$string['allgroups'] = 'Todos os grupos';
$string['group'] = 'Grupo';
$string['from'] = 'Data inicial';
$string['to'] = 'Data final';
$string['apply'] = 'Aplicar';
$string['clearfilters'] = 'Limpar filtros';
$string['studentsingroup'] = '{$a} alunos no grupo';
$string['enrolled'] = 'Número de matriculados';
$string['enrolled_note'] = 'Alunos que confirmaram a matrícula com SIM';
$string['active'] = 'Alunos ativos';
$string['active_note'] = '{$a}% dos alunos matriculados';
$string['activities'] = 'Número de atividades';
$string['activities_note'] = 'Com conclusão e nota ativadas';
$string['dropout'] = 'Taxa de evasão';
$string['dropout_note'] = '{$a->suspended} alunos suspensos de {$a->total}';
$string['nps'] = 'NPS (Net Promoter Score)';
$string['nps_note'] = '{$a} respostas recebidas';
$string['nps_missing'] = 'Nenhuma pesquisa NPS encontrada. Adicione uma atividade Feedback cuja primeira pergunta seja de múltipla escolha, com opções de 0 a 10.';
$string['completionrate'] = 'Taxa de conclusão';
$string['completionrate_note'] = '{$a->done} de {$a->total} alunos concluíram o curso';
$string['completion_disabled'] = 'A conclusão do curso não está ativada.';
$string['averagegrade'] = 'Média de notas';
$string['averagegrade_note'] = '{$a} alunos com notas registradas';
$string['outof10'] = 'de 10';
$string['engagement'] = 'Engajamento';
$string['engagement_desc'] = 'Logs, cliques e interações ao longo do tempo';
$string['clicks'] = 'Cliques';
$string['logs'] = 'Logs';
$string['interactions'] = 'Interações';
$string['completions'] = 'Conclusão de atividades';
$string['completions_desc'] = 'Evolução das conclusões de todos os tipos de atividade';
$string['completionsseries'] = 'Conclusões';
$string['lastmonths'] = 'Últimos {$a} meses';
$string['granularity'] = 'Granularidade';
$string['monthly'] = 'Mensal';
$string['weekly'] = 'Semanal';
$string['daily'] = 'Diário';
$string['allactivities'] = 'Todas as atividades';
$string['clicksinperiod'] = '{$a} cliques no período';
$string['completionsinperiod'] = '{$a} conclusões no período';
$string['vsprev_month'] = 'vs. mês anterior';
$string['vsprev_week'] = 'vs. semana anterior';
$string['vsprev_day'] = 'vs. dia anterior';
$string['viewcompletions'] = 'Ver as atividades completas';
$string['nodata'] = 'Sem dados no período.';
$string['footer_title'] = 'Relatórios do curso';
$string['footer_desc'] = 'Matrículas, notas, logs e conclusões do Moodle · NPS via pesquisa do curso';
$string['summary'] = 'Resumo';
$string['period'] = 'Período';
$string['indicator'] = 'Indicador';
$string['value'] = 'Valor';
$string['details'] = 'Detalhes';
$string['confirmationactivitymissing'] = 'Não foi encontrada uma atividade Quiz ou Feedback com Número ID "confirmacao_matricula" neste curso. Configure a atividade de confirmação antes de abrir o relatório.';
$string['confirmationactivityduplicate'] = 'Mais de uma atividade Quiz ou Feedback usa o Número ID "confirmacao_matricula". Mantenha esse Número ID em apenas uma atividade por curso.';
$string['confirmationquizinvalid'] = 'O Quiz de confirmação precisa ter exatamente uma pergunta, nota de aprovação maior que zero e nota máxima suficiente. Configure SIM como aprovado e NÃO como não aprovado.';
$string['confirmationfeedbackanonymous'] = 'O Feedback de confirmação precisa ser não anônimo para que as respostas possam ser associadas aos estudantes.';
$string['confirmationfeedbackinvalid'] = 'O Feedback de confirmação precisa ter exatamente uma pergunta de escolha única com as opções SIM e NÃO.';
