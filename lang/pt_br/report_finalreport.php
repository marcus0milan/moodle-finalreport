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

$string['pluginname'] = 'Relatório final do curso';
$string['finalreport:view'] = 'Ver o relatório final do curso';
$string['finalreport:export'] = 'Baixar o relatório final em PDF';
$string['privacy:metadata'] = 'O plugin Relatório final do curso apenas exibe dados que já existem no Moodle e não armazena dados pessoais.';
$string['eventreportviewed'] = 'Relatório final do curso visualizado';
$string['reporttitle'] = 'Relatório final do curso';
$string['reportdescription'] = 'Indicadores de conclusão, notas e engajamento dos participantes matriculados.';
$string['downloadpdf'] = 'Baixar relatório em PDF';
$string['chartview'] = 'Visão gráfica';
$string['tableview'] = 'Tabela de atividades';
$string['generatedat'] = 'Gerado em {$a}';
$string['participants'] = 'Participantes acompanhados';
$string['coursecompletion'] = 'Conclusão do curso';
$string['completedparticipants'] = 'Participantes concluídos';
$string['averagegrade'] = 'Média geral';
$string['gradedparticipants'] = 'Participantes com nota';
$string['interactions'] = 'Interações registradas';
$string['views'] = 'Visualizações';
$string['activeparticipants'] = 'Participantes ativos nos logs';
$string['completionnotconfigured'] = 'A conclusão do curso não está habilitada ou não possui critérios configurados. A taxa de conclusão ficará indisponível até a configuração ser concluída.';
$string['lognotavailable'] = 'O armazenamento padrão de logs não está habilitado. Visualizações, interações e níveis de engajamento não estão disponíveis.';
$string['lognotavailableshort'] = 'Sem dados de log';
$string['lognote'] = 'Os dados de acesso consideram os eventos atualmente retidos no armazenamento padrão de logs do Moodle. Eventos registrados enquanto um administrador estava logado como o participante são ignorados.';
$string['gradesneedupdate'] = 'Alguns itens do livro de notas estão aguardando recálculo. As notas exibidas podem estar desatualizadas até o Moodle recalcular o livro de notas.';
$string['emptyfilter'] = 'Nenhum tipo de atividade foi selecionado, então todos os tipos foram considerados.';
$string['restrictedtogroups'] = 'Este curso usa grupos separados: o relatório considera apenas os participantes dos seus grupos.';
$string['nogrades'] = 'Ainda não há notas finais do curso para os participantes acompanhados.';
$string['noactivities'] = 'Não há atividades disponíveis para análise neste curso.';
$string['activityanalysis'] = 'Desempenho e engajamento por atividade';
$string['activity'] = 'Atividade';
$string['type'] = 'Tipo';
$string['uniqueviews'] = 'Participantes ativos';
$string['lastinteraction'] = 'Última interação';
$string['never'] = 'Nunca';
$string['notavailable'] = 'Não disponível';
$string['gradepercent'] = 'Média normalizada';
$string['gradedistribution'] = 'Distribuição das notas';
$string['gradesbycategory'] = 'Média por categoria de nota';
$string['finalgrade'] = 'Média final';
$string['frequencyreference'] = 'referência {$a}%';
$string['belowfrequency'] = '{$a->count} de {$a->total} participantes abaixo de {$a->limit}%';
$string['activityinteractions'] = 'Interações por atividade';
$string['engagementlevels'] = 'Níveis de engajamento';
$string['engagementlow'] = 'Fraco engajamento';
$string['engagementmedium'] = 'Médio engajamento';
$string['engagementhigh'] = 'Alto engajamento';
$string['engagementcriteria'] = 'Fraco: menos de 1/3 das atividades acessadas. Médio: de 1/3 a menos de 2/3. Alto: 2/3 ou mais.';
$string['completionoverview'] = 'Visão geral da conclusão';
$string['range0_59'] = '0–59%';
$string['range60_69'] = '60–69%';
$string['range70_79'] = '70–79%';
$string['range80_89'] = '80–89%';
$string['range90_100'] = '90–100%';
$string['completiondisabled'] = 'Conclusão não configurada';
$string['pdfsubtitle'] = 'Indicadores acadêmicos e de engajamento';
$string['pdffilename'] = 'relatorio-final';
$string['pdffilterapplied'] = 'Tipos de atividade incluídos: {$a}';
$string['activitycompletion'] = 'Conclusão da atividade';
$string['filteractivitytypes'] = 'Tipos de atividades e recursos';
$string['filteractivitytypeshelp'] = 'Selecione um ou mais tipos para incluir na análise de atividades e nos indicadores de interações.';
$string['applyfilters'] = 'Aplicar filtros';
$string['clearfilters'] = 'Limpar filtros';
$string['frequencythreshold'] = 'Referência de frequência (%)';
$string['frequencythreshold_desc'] = 'Percentual usado como referência no gráfico da categoria de frequência (conta os participantes abaixo desse valor). Não altera nenhuma nota. Padrão: 75.';
