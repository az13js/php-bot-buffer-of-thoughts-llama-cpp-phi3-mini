'''
文心一言版本的BoT
使用需要安装
pip install qianfan
需要设置环境变量
QIANFAN_ACCESS_KEY=<参考百度相关文档获取的access key>
QIANFAN_SECRET_KEY=<参考百度相关文档获取的secret key>
BAIDU_MODEL=ERNIE-Lite-8K
最后一个是可选的，qianfan模块不会读取这个环境变量但是脚本用它选择模型。模型需要提前申请开通。
'''

import qianfan
import os
import json
import random
import argparse

from typing import List, Optional

def runModel(userPrompt: str, systemPrompt: str = '') -> str:
    '''调用文心一言回答问题。
    userPrompt: 用户的问题。
    systemPrompt: 系统提示。可选，默认空字符串。
    return: 回答。
    '''
    modelName: str = 'ERNIE-Lite-8K'
    if 'BAIDU_MODEL' in os.environ:
        modelName = os.environ['BAIDU_MODEL']

    sdk = qianfan.ChatCompletion()
    resp = sdk.do(
        model=modelName,
        messages=[{'role':'user','content':userPrompt}],
        system=systemPrompt
    )
    return resp['result']

def problemDistillation(question: str) -> str:
    '''问题蒸馏。
    question: 用户的问题。
    return: 蒸馏后的问题关键点。
    '''
    return runModel(question, '提取并列出问题中的关键信息。重要：不要回答、不要分析。')

def answerQuestion(question: str, distilledInformation: str, thoughtTemplate: str = '') -> str:
    '''综合蒸馏信息和思维模板，回答用户问题。
    question: 用户的问题。
    distilledInformation: 问题蒸馏后的关键信息。
    thoughtTemplate: 思维模板。可选，默认空字符串。
    '''
    if '' == thoughtTemplate:
        systemPrompt = f'''根据以下信息回答问题：

{distilledInformation}'''
        return runModel(question, systemPrompt)
    else:
        systemPrompt = f'''根据以下蒸馏信息和思维模板回答问题：

蒸馏信息开始
{distilledInformation}
蒸馏信息结束

思维模板开始
{thoughtTemplate}
思维模板结束'''
        return runModel(question, systemPrompt)

def getThoughtTemplate(question: str, answer: str) -> str:
    '''根据问题和答案提取思维模板。
    question: 用户的问题。
    answer: 问题的答案。
    return: 思维模板。
    '''
    begin = '[begin]'
    end = '[end]'

    prompt = f'''你善于从具体问题中提出通用的解决方法，形成思维的模板，便于在将来更容易解决类似的问题。

以下是具体的问题和一个可能的回答。请你提取思维模板。你的思维模板需要以 {begin} 开头，以 {end} 结束。

问题开始
{question}
问题结束

回答开始
{answer}
回答结束'''
    info = runModel(prompt)

    if begin in info and end in info:
        return info[info.index(begin) + len(begin):info.index(end)].strip()
    else:
        raise Exception('Find template failed')

def getThoughtTemplateTitle(thoughtTemplate: str) -> str:
    '''获取思维导图模板的标题
    thoughtTemplate: 思维导图模板
    return: 标题
    '''
    begin = '[begin]'
    end = '[end]'

    prompt = f'''为了方便以后的需要，方便根据标题找到文章，现在有一篇缺少标题的文章内容如下，你需要为文章起一个准确的标题。

文章开始
{thoughtTemplate}
文章结束

你的标题需要以 {begin} 开头，以 {end} 结束。你直接给出标题即可，无需多言。'''

    info = runModel(prompt, '你善于总结和整理文章素材，为将来的使用和查找做准备。')

    if begin in info and end in info:
        return info[info.index(begin) + len(begin):info.index(end)].strip()
    else:
        raise Exception('Find title failed')

class ThoughtTemplate:
    def __init__(self, title: str, content: str, filePath: str = '') -> None:
        self.title = title
        self.content = content
        self.filePath = filePath

    def __str__(self) -> str:
        return f'[title={self.title},content={self.content},path={self.filePath}]'

def getAllThoughtTemplates() -> List[ThoughtTemplate]:
    '''返回存储的所有思维模板。
    return: List[ThoughtTemplate]
    '''
    # 如果本地文件夹不存在，则创建
    if not os.path.exists('thought_templates'):
        os.mkdir('thought_templates')

    thoughtTemplates = []
    for fileName in os.listdir('thought_templates'):
        filePath = os.path.join('thought_templates', fileName)
        with open(filePath, 'r', encoding='utf-8') as f:
            content = json.loads(f.read())
            thoughtTemplates.append(ThoughtTemplate(content['title'], content['content'], filePath))

    return thoughtTemplates

def selectThoughtTemplate(question: str, thoughtTemplates: List[ThoughtTemplate]) -> Optional[ThoughtTemplate]:
    '''从模板中选择一个最匹配的模板
    question: 问题
    thoughtTemplates: 模板列表
    return: 模板或None
    '''
    if len(thoughtTemplates) == 0:
        return None

    begin = '['
    end = ']'

    itemsBlock = ''
    for k, thoughtTemplate in enumerate(thoughtTemplates):
        idx = k + 1
        itemsBlock = f'''{itemsBlock}
{begin}{idx}{end} {thoughtTemplate.title}'''

    idx = len(thoughtTemplates) + 1
    itemsBlock = f'''{itemsBlock}
{begin}{idx}{end} 没有可选项'''

    prompt = f'''目前有以下问题需要回答：
{question}
问题结束。

为了回答问题，现在有以下文章可以参考：
{itemsBlock}

你必须从中选择一个选项，选项以 {begin} 开始以 {end} 结束，例如： {begin}1{end} 。你只需要选择，无需分析问题，也无需回答问题。无需多言。'''
    info = runModel(prompt, '你善于从文章中参考有用信息解决问题。当你遇到需要从多篇文章中选择一个时，你总会尽可能选择有用的合适的那一篇文章。当然，文章有时是通用的，并不一定针对具体问题，实在没有有效的文章时，选择一篇可能有用的文章也比什么文章都不选要好。')
    if begin in info and end in info:
        option = int(info[info.index(begin) + len(begin):info.index(end)].strip()) - 1
        if option in range(len(thoughtTemplates)):
            return thoughtTemplates[option]
        else:
            return None
    return None

def selectBestThoughtTemplate(question: str, thoughtTemplate1: ThoughtTemplate, thoughtTemplate2: ThoughtTemplate) -> ThoughtTemplate:
    a = thoughtTemplate1
    b = thoughtTemplate2
    if random.random() < 0.5:
        a, b = b, a

    begin = '<<'
    end = '>>'

    prompt = f'''针对以下问题：

问题开始
{question}
问题结束

从下述2个思维模板（{begin}1{end} 或者 {begin}2{end}）中选择最适合解决问题的一个：

{begin}1{end}

{a.title}

{a.content}

{begin}2{end}

{b.title}

{b.content}

你必须从以上2个中选择一个选项，选项以 {begin} 开始以 {end} 结束，例如： {begin}1{end} 。你只需要选择，无需分析问题，也无需回答问题。无需多言。'''
    info = runModel(prompt, '你善于根据问题选择合适的思维模板')
    if f'{begin}1{end}' not in info and f'{begin}2{end}' not in info:
        # 随机返回一个
        return a
    else:
        if f'{begin}1{end}' in info:
            return a
        if f'{begin}2{end}' in info:
            return b
    # 随机返回一个
    return a

if '__main__' == __name__:
    parser = argparse.ArgumentParser(description='文心一言BoT')
    parser.add_argument('-p', type=str, help='问题', default='编写Python代码寻找函数 x^3+2*x-10 在区间 [0,100] 的最大值并输出对应的横坐标 x')
    args = parser.parse_args() 

    question = args.p

    thoughtTemplates = getAllThoughtTemplates()
    thoughtTemplate = selectThoughtTemplate(question, thoughtTemplates)

    distilledInformation = problemDistillation(question)
    answer = answerQuestion(question, distilledInformation, '' if thoughtTemplate is None else thoughtTemplate.content)

    thoughtTemplateContent = getThoughtTemplate(question, answer)
    thoughtTemplateTitle = getThoughtTemplateTitle(thoughtTemplateContent)
    newThoughtTemplate = ThoughtTemplate(thoughtTemplateTitle, thoughtTemplateContent)
    if thoughtTemplate is None:
        fileName = str(len(thoughtTemplates)) + '.json'
        filePath = os.path.join('thought_templates', fileName)
        with open(filePath, 'w') as f:
            json.dump({'title': newThoughtTemplate.title, 'content': newThoughtTemplate.content}, f)
    else:
        newThoughtTemplate.filePath = thoughtTemplate.filePath
        bestThoughtTemplate = selectBestThoughtTemplate(question, thoughtTemplate, newThoughtTemplate)
        filePath = bestThoughtTemplate.filePath
        with open(filePath, 'w') as f:
            json.dump({'title': bestThoughtTemplate.title, 'content': bestThoughtTemplate.content}, f)

    print(answer)
