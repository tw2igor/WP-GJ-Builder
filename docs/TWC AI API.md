---
type: notes
project: TWH
date: "2026-07-20"
tags:
status: in_progress
---
### Call AI agent

​Path Parameters
- agent_access_idCopy link to agent_access_id
    Type:string
    required
    Agent access ID

Headers
- AuthorizationCopy link to Authorization
    Type:string
    required
    Example
    Bearer token
- x-proxy-sourceCopy link to x-proxy-source
    Type:string
    required

Body·AgentCallDto
required
application/json
- file_idsCopy link to file_ids
    Type:array string[]
- messageCopy link to message
    Type:string
    Default
- metadataCopy link to metadata
    Type:object
- parent_message_idCopy link to parent_message_id
    Type:string

Responses

- 200 Agent response application/json
- 401 Unauthorized
- 404     Agent not found

curl 'https://agent.timeweb.cloud/api/v1/cloud-ai/agents/{agent_access_id}/call' \
  --request POST \
  --header 'Authorization: Bearer <token>' \
  --header 'x-proxy-source: ' \
  --header 'Content-Type: application/json' \
  --data '{
  "message": "",
  "parent_message_id": "",
  "file_ids": [
    ""
  ],
  "metadata": {}
}'


### OpenAI-compatible chat completions endpoint for AI agent

This endpoint supports both simple text messages and multimodal content:

**Simple text message:**

```json
{
  "model": "gpt-4",
  "messages": [
    {
      "role": "user",
      "content": "Hello, how are you?"
    }
  ]
}
```

**Multimodal message with text and image:**

```json
{
  "model": "gpt-4",
  "messages": [
    {
      "role": "user",
      "content": [
        { "type": "text", "text": "What is in this image?" },
        { "type": "image_url", "image_url": { "url": "https://example.com/image.jpg" } }
      ]
    }
  ]
}
```

**Message with audio input:**

```json
{
  "model": "gpt-4",
  "messages": [
    {
      "role": "user",
      "content": [
        { "type": "text", "text": "Please transcribe this audio:" },
        { "type": "input_audio", "input_audio": { "data": "base64_encoded_audio_data", "format": "wav" } }
      ]
    }
  ]
}
```

Path Parameters

- agent_access_idCopy link to agent_access_id
    
    Type:string
    
    required
    
    Agent access ID
    

Headers

- AuthorizationCopy link to Authorization
    
    Type:string
    
    required
    
    Example
    
    Bearer token
    
- x-proxy-sourceCopy link to x-proxy-source
    
    Type:string
    
    required
    

Body·ChatCompletionCreateParamsDto

required

application/json

- messagesCopy link to messages
    
    Type:array object[] · ChatMessageDto[]
    
    required
    
    A list of messages comprising the conversation so far
    
    Show Child Attributesfor messages
    
- frequency_penaltyCopy link to frequency_penalty
    
    Type:object
    
    Example
    
    Number between -2.0 and 2.0. Positive values penalize new tokens based on their existing frequency in the text so far, decreasing the model's likelihood to repeat the same line verbatim.
    
- logit_biasCopy link to logit_bias
    
    Type:object
    
    Example
    
    Modify the likelihood of specified tokens appearing in the completion
    
    Show Child Attributesfor logit_bias
    
- logprobsCopy link to logprobs
    
    Type:object
    
    Example
    
    Whether to return log probabilities of the output tokens
    
- max_completion_tokensCopy link to max_completion_tokens
    
    Type:object
    
    Example
    
    The maximum number of tokens to generate in the chat completion (alternative to max_tokens)
    
- max_tokensCopy link to max_tokens
    
    Type:object
    
    deprecated
    
    Example
    
    The maximum number of tokens to generate in the chat completion
    
- modelCopy link to model
    
    Type:object
    
    Example
    
    ID of the model to use. This field is ignored as the agent has its own model configuration.
    
- nCopy link to n
    
    Type:object
    
    Example
    
    How many chat completion choices to generate for each input message
    
- presence_penaltyCopy link to presence_penalty
    
    Type:object
    
    Example
    
    Number between -2.0 and 2.0. Positive values penalize new tokens based on whether they appear in the text so far, increasing the model's likelihood to talk about new topics.
    
- response_formatCopy link to response_format
    
    An object specifying the format that the model must output
    
    One ofResponseFormatTextDto
    
    An object specifying the format that the model must output
    
    - type
        
        enum
        
        const:  
        
        text
        
        required
        
        Example
        
        The type of response format
        
        values
        
        - text
            
        
    
- stopCopy link to stop
    
    Up to 4 sequences where the API will stop generating further tokens
    
    One ofstring
    
    - Type:string
        
        Example
        
        Up to 4 sequences where the API will stop generating further tokens
        
    
- streamCopy link to stream
    
    Type:object
    
    Default
    
    Example
    
    Whether to stream back partial responses
    

Show additional propertiesfor Request Body

Responses

- 200
    
    Chat completion response (non-streaming)
    
    application/json
    
- 401
    
    Unauthorized
    
- 404
    
    Agent not found

curl 'https://agent.timeweb.cloud/api/v1/cloud-ai/agents/{agent_access_id}/v1/chat/completions' \
  --request POST \
  --header 'Authorization: Bearer <token>' \
  --header 'x-proxy-source: ' \
  --header 'Content-Type: application/json' \
  --data '{
  "model": "gpt-4",
  "messages": [
    {
      "role": "system",
      "content": "Simple text message",
      "name": "user123",
      "function_call": {
        "name": "get_weather"
      },
      "tool_calls": {},
      "tool_call_id": "call_abc123"
    }
  ],
  "temperature": 0.7,
  "top_p": 1,
  "n": 1,
  "stream": false,
  "stop": [
    "\n",
    "Human:"
  ],
  "max_completion_tokens": 100,
  "presence_penalty": 0,
  "frequency_penalty": 0,
  "logit_bias": {
    "50256": -100
  },
  "user": "user-1234",
  "response_format": {
    "type": "text"
  },
  "tools": {
    "type": "function",
    "function": {
      "additionalProperty": "anything"
    }
  },
  "tool_choice": "auto",
  "stream_options": {
    "include_usage": true
  },
  "logprobs": false,
  "top_logprobs": 0
}'

