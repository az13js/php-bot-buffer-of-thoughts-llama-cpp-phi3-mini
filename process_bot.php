<?php
/**
 * PHP版BoT(Buffer of Thoughts)
 *
 * 使用：
 * 1. composer install
 * 2. 部署llama.cpp项目，Github上官方有提供可执行文件可以下载。此外自行下载Phi3模型文件。
 * 3. 配置环境变量：
 * PCLOCAL_LLAMA_MODEL=/<你本地保存Phi3模型文件路径>/Phi-3-mini-4k-instruct-q4.gguf
 * PCLOCAL_LLAMA_EXE=/<你本地部署的llama.cpp文件夹路径>/llama-cli
 * PCLOCAL_LLAMA_THREAD=8 # 线程数
 * 4. 运行：php process_bot.php -p '<这里输入你需要解决的问题>'
 *
 * 本地文件夹 thought_templates 会自动创建，保存思维模板。思维模板会在每次执行
 * process_bot.php 时读取、更新或创建。
 *
 * 注：
 * 1. 提示词适配Phi3mini，其它种类的大模型不一定适配。
 * 2. 可能抛异常中断，中断可以重试看能不能重试成功。
 */

require_once __DIR__ . '/vendor/autoload.php';

define('THOUGHT_TEMPLATES_DIR', 'thought_templates') || die('THOUGHT_TEMPLATES_DIR is not defined');

/**
 * 问题蒸馏
 *
 * 对给定的问题产生一个指导AI如何从用户输入的信息中提取关键内容的指令。
 *
 * @param string $question 问题
 * @return string 指令
 */
function problemDistillation(string $question): string
{
    $distill = <<<DISTILL_PROMPT
You are a highly intelligent AI system. You need to extract key information, boundary conditions, and constraints from user questions. Last but not least, do not answer user's questions, don't say unnecessary content.
DISTILL_PROMPT;

    return runModel($question, $distill);
}

/**
 * 回答用户的问题
 *
 * @param string $question 用户输入的问题
 * @param string $distilledInformation 如何提取信息的指令
 * @param string $thoughtTemplate 如何解决问题的思维模板
 * @return string 用户的问题的答案
 */
function answerQuestion(string $question, string $distilledInformation, string $thoughtTemplate = ''): string
{
    if (empty($thoughtTemplate)) {
        $system = <<<SYSTEM_PROMPT
Use the following techniques to extract key information from the question and answer the user's question:

techniques begin

$distilledInformation

techniques end
SYSTEM_PROMPT;
    } else {
        $system = <<<SYSTEM_PROMPT_WITH_TEMPLATE
You adept at using the following techniques to extract information from user questions and answer them empirically using the following experience.

techniques begin

$distilledInformation

techniques end

experience begin

$thoughtTemplate

experience end
SYSTEM_PROMPT_WITH_TEMPLATE;
    }
    return runModel($question, $system);
}

/**
 * 从提问和回答中提取思维模板
 *
 * @param string $question 问题
 * @param string $answer 回答
 * @return string 思维模板
 */
function getThoughtTemplate(string $question, string $answer): string
{
    $systemPrompt = <<<SYSTEM_PROMPT_TEMPLATE
You are an AI assistant adept at extracting experience. You are good at abstracting thinking methods to solve the same problem from specific problems.
SYSTEM_PROMPT_TEMPLATE;

    $prompt = <<<QUESTION_PROMPT
Abstract a general thinking method (thought template) for solve the same kind of question from the following question and answer:

question begin
$question
question end

answer begin
$answer
answer end
QUESTION_PROMPT;

    return runModel($prompt, $systemPrompt);
}

/**
 * 为给定的思维模板起一个标题
 *
 * @param string $thoughtTemplate 思维模板
 * @return string 思维模板标题
 */
function getThoughtTemplateTitle(string $thoughtTemplate): string
{
    $start = '[title begin]';
    $end = '[title end]';

    $systemPrompt = <<<SYSTEM_PROMPT_TITLE
You are a writer who frequently searches for information and writes titles and content for it. You are using your expertise to solve problems for others.
SYSTEM_PROMPT_TITLE;

    $prompt = <<<QUESTION_PROMPT_TITLE
Write a title for the following thought template:

[template begin]
$thoughtTemplate
[template end]

For ease of searching, the title needs to be appropriate. The title needs to start with `$start` and end with `$end`. Just tell me the title, No need to say anything else.
QUESTION_PROMPT_TITLE;

    $result = runModel($prompt, $systemPrompt);
    $posStart = strpos($result, $start);
    $posEnd = strpos($result, $end);
    if (false === $posStart || false === $posEnd) {
        throw new LogicException('Invalid result: can not find title, LLM output:' . $result);
    }
    $posStart += strlen($start);
    if ($posStart >= $posEnd) {
        throw new LogicException('Invalid result: title start position is greater than end position, LLM output:' . $result);
    }
    return trim(substr($result, $posStart, $posEnd - $posStart));
}

class ThoughtTemplate
{
    public string $title;
    public string $content;
    public string $filePath;

    public function __construct(string $title, string $content, string $filePath = '')
    {
        $this->title = $title;
        $this->content = $content;
        $this->filePath = $filePath;
    }
}

/**
 * 返回思维模板信息
 *
 * @return ThoughtTemplate[] 思维模板
 */
function getAllThoughtTemplates(): array
{
    $dir = implode(DIRECTORY_SEPARATOR, [__DIR__, THOUGHT_TEMPLATES_DIR]);
    if (!is_dir($dir)) {
        mkdir($dir);
    }
    $dh = opendir($dir);
    if (false === $dh) {
        return [];
    }
    $thoughtTemplates = [];
    while (($fileName = readdir($dh)) !== false) {
        if (in_array($fileName, ['.', '..'])) {
            continue;
        }
        $filePath = implode(DIRECTORY_SEPARATOR, [$dir, $fileName]);
        if (is_file($filePath)) {
            $thoughtTemplate = json_decode(file_get_contents($filePath), true);
            if (is_array($thoughtTemplate)) {
                $thoughtTemplates[] = new ThoughtTemplate(
                    $thoughtTemplate['title'],
                    $thoughtTemplate['content'],
                    $filePath
                );
            }
        }
    }
    closedir($dh);
    return $thoughtTemplates;
}

/**
 * 根据给定问题选择一个思维模板
 *
 * @param string $question 问题
 * @param ThoughtTemplate[] $thoughtTemplates 思维模板数组
 * @return null|ThoughtTemplate 当没有可选的思维模板时，返回null
 */
function selectThoughtTemplate(string $question, array $thoughtTemplates)
{
    if (empty($thoughtTemplates)) {
        return null;
    }

    $id = 0;
    $items = array_map(function (ThoughtTemplate $thoughtTemplate) use (&$id) {
        ++$id;
        return "($id) {$thoughtTemplate->title}";
    }, $thoughtTemplates);
    $itemsBlock = implode(PHP_EOL, $items);
    ++$id;
    $itemsBlock .= (PHP_EOL . "($id) No available options");

    $prompt = <<<SELECT_TEMPLE
There is currently a question:

question begin
$question
question end

The following options provide some articles that may be used to guide me in solving problems:
$itemsBlock

Tell me which option is the most suitable. You can only choose one option. [Your answer, option are enclosed in parentheses '()', For example: (1).]
The important thing is that you just need to choose, don't say unnecessary content, and don't explain why.
SELECT_TEMPLE;

    $result = runModel($prompt);
    for ($i = count($thoughtTemplates); $i > 0; $i--) {
        if (strpos($result, "($i)") !== false) {
            return $thoughtTemplates[$i - 1];
        }
    }
    return null;
}

/**
 * 根据问题判断，哪个思维模板更好
 *
 * @param string $question 问题
 * @param ThoughtTemplate $thoughtTemplate1 思维模板1
 * @param ThoughtTemplate $thoughtTemplate2 思维模板2
 * @return ThoughtTemplate 思维模板1或2
 */
function selectBestThoughtTemplate(string $question, ThoughtTemplate $thoughtTemplate1, ThoughtTemplate $thoughtTemplate2): ThoughtTemplate
{
    if (mt_rand() % 2 === 0) {
        $a = $thoughtTemplate1;
        $b = $thoughtTemplate2;
    } else {
        $a = $thoughtTemplate2;
        $b = $thoughtTemplate1;
    }
    $prompt = <<<SELECT_BEST_TEMPLE
There is currently a question:

question begin
$question
question end

The following options provide some articles that may be used to guide me in solving problems:

<<1>> {$a->title}

{$a->content}
End of <<1>>.

<<2>> {$b->title}

{$b->content}
End of <<2>>.

Which option is the most suitable for solving the problem? Is `<<1>>` or `<<2>>` ? You must choose an answer from among them.
Your answer, should begin with '<<' and end with '>>'. If there are no suitable options, please reply directly: <<no suitable options>>.
Just select option, don't say unnecessary content, don't explain why.
SELECT_BEST_TEMPLE;
    $result = runModel($prompt);
    if (strpos($result, '<<no suitable options>>') !== false) {
        return mt_rand() % 2 === 0 ? $a : $b;
    }
    if (strpos($result, '<<1>>') !== false && strpos($result, '<<2>>') === false) {
        return $a;
    }
    if (strpos($result, '<<2>>') !== false && strpos($result, '<<1>>') === false) {
        return $b;
    }
    return mt_rand() % 2 === 0 ? $a : $b;
}

$question = <<<QUESTION
Write Python code to find the maximum value of x^3+2*x-10 in the interval [0,100] and output the corresponding x.
QUESTION;

$commands = getopt('p:');
if (isset($commands['p'])) {
    $question = $commands['p'];
}

$thoughtTemplates = getAllThoughtTemplates();
$thoughtTemplate = selectThoughtTemplate($question, $thoughtTemplates);

$distilledInformation = problemDistillation($question);
$answer = answerQuestion($question, $distilledInformation, is_null($thoughtTemplate) ? '' : $thoughtTemplate->content);

$thoughtTemplateContent = getThoughtTemplate($question, $answer);
$thoughtTemplateTitle = getThoughtTemplateTitle($thoughtTemplateContent);
$newThoughtTemplate = new ThoughtTemplate($thoughtTemplateTitle, $thoughtTemplateContent);
if (is_null($thoughtTemplate)) {
    $filePath = implode(DIRECTORY_SEPARATOR, [__DIR__, THOUGHT_TEMPLATES_DIR, count($thoughtTemplates) . '.json']);
    file_put_contents($filePath, json_encode([
        'title' => $thoughtTemplateTitle,
        'content' => $thoughtTemplateContent,
    ]));
} else {
    $newThoughtTemplate->filePath = $thoughtTemplate->filePath;
    $bestThoughtTemplate = selectBestThoughtTemplate($question, $thoughtTemplate, $newThoughtTemplate);
    file_put_contents($bestThoughtTemplate->filePath, json_encode([
        'title' => $bestThoughtTemplate->title,
        'content' => $bestThoughtTemplate->content,
    ]));
}

echo $answer . PHP_EOL;
