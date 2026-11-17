## Extensão para o Moodle - Painel para visualização de dados acadêmicos e geração de relatórios finais para gestão

Esse plugin tem como objetivo implementar um painel para melhor visualização por meio de gráficos, KPIs os indicadores acadêmicos de um curso, tanto para acompanhamento, quanto para visualizar sua conclusão e gerar os relatórios.

## Requisitos
- Moodle 4.4, 4.5 ou 4.6;
- Versão do PHP compatível com o Moodle instalado
- Caso o curso possua indicadores de avaliação, irão aparecer.

## Instalação

1. Realize o download do arquivo .zip

2. Em Administração do Site > Plugins > Instalar Plugins 

3. Jogue ou insira o arquivo .zip

Para acessar o paínel, ele fica dentro de um curso, no menu de Relatórios.

## Confirmação de matrícula

Os indicadores e gráficos por estudante consideram somente estudantes que confirmaram a matrícula com SIM. Em cada curso, identifique a atividade Quiz ou Feedback de confirmação pelo campo **Número ID**, usando exatamente `confirmacao_matricula`. Deve haver apenas uma atividade marcada por curso.

- No Quiz, use exatamente uma pergunta, configure SIM como aprovado, NÃO como não aprovado e uma nota de aprovação maior que zero.
- No Feedback, use uma atividade não anônima com exatamente uma pergunta de escolha única e as opções SIM e NÃO.

Atividades não configuradas ou com configuração ambígua geram um erro explícito, para evitar que o relatório apresente contagens incompletas como se fossem válidas.

## Em desenvolvimento . . 

