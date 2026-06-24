<?php

namespace app\admin\controller\muuAgent;

use app\admin\controller\Admin;
use app\common\service\MuuAgent;

/**
 * @title MCP Server管理接口
 * @package app\admin\controller\muuAgent
 *
 * 通过 MuuAgent 中台管理端接口管理 MCP Server
 * 支持三种传输协议：HTTP、SSE、STDIO
 */
class McpServer extends Admin
{
    /** @var MuuAgent MuuAgent 中台客户端 */
    protected MuuAgent $muuAgent;

    public function __construct()
    {
        parent::__construct();
        $this->muuAgent = new MuuAgent();
    }

    /**
     * 获取 MCP Server 列表（分页）
     *
     * @return mixed 返回MCP Server列表数据
     */
    public function list()
    {
        $page        = (int)input('get.page', 1, 'intval');           // 页码，从1开始
        $pageSize    = (int)input('get.page_size', 10, 'intval');     // 每页条数
        $enabled     = (string)input('get.enabled', '', 'text');      // 是否启用筛选
        $healthStatus = (string)input('get.health_status', '', 'text'); // 健康状态筛选
        $transport   = (string)input('get.transport', '', 'text');    // 传输协议筛选

        $data = [
            'page'     => $page,
            'pageSize' => $pageSize,
            'appCode'  => $this->muuAgent->getAppCode(), // 从扩展配置自动获取
        ];

        if (!empty($enabled)) {
            $data['enabled'] = $enabled === 'true' || $enabled === '1';
        }
        if (!empty($healthStatus)) {
            $data['healthStatus'] = $healthStatus;
        }
        if (!empty($transport)) {
            $data['transport'] = $transport;
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/mcp-server', $data);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取 MCP Server 详情
     *
     * @return mixed 返回MCP Server详情数据
     */
    public function detail()
    {
        $id = (string)input('get.id', '', 'text');  // MCP Server ID

        if (empty($id)) {
            return $this->error('MCP Server ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/mcp-server/' . $id);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 创建 MCP Server
     *
     * @return mixed 返回创建结果
     */
    public function create()
    {
        $name        = (string)input('post.name', '', 'text');        // MCP Server名称（唯一标识）
        $displayName = (string)input('post.display_name', '', 'text'); // 显示名称
        $description = (string)input('post.description', '', 'text'); // 描述
        $transport   = (string)input('post.transport', 'http', 'text'); // 传输协议（http/sse/stdio）
        $url         = (string)input('post.url', '', 'text');        // HTTP/SSE端点地址
        $command     = (string)input('post.command', '', 'text');    // stdio命令
        $args        = (string)input('post.args', '', 'text');       // stdio参数（JSON数组）
        $env         = (string)input('post.env', '', 'text');        // 环境变量（JSON对象）
        $apiKey      = (string)input('post.api_key', '', 'text');    // API密钥
        $timeout     = (int)input('post.timeout', 30000, 'intval');  // 超时时间（毫秒）
        $enabled     = (bool)input('post.enabled', true, 'bool');    // 是否启用
        $tools       = (string)input('post.tools', '', 'text');      // 允许的工具列表（JSON数组）
        $metadata    = (string)input('post.metadata', '', 'text');   // 扩展元数据（JSON对象）

        if (empty($name)) {
            return $this->error('MCP Server 名称不能为空');
        }

        // 验证名称格式
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $name)) {
            return $this->error('名称必须以字母开头，只能包含字母、数字、下划线和连字符');
        }

        // 验证传输协议必填字段
        if ($transport === 'http' || $transport === 'sse') {
            if (empty($url)) {
                return $this->error('HTTP/SSE 协议需要提供端点地址');
            }
        } elseif ($transport === 'stdio') {
            if (empty($command)) {
                return $this->error('STDIO 协议需要提供命令');
            }
        }

        $data = [
            'name'      => $name,
            'transport' => $transport,
            'timeout'   => $timeout,
            'enabled'   => $enabled,
            'appCode'   => $this->muuAgent->getAppCode(), // 从扩展配置自动获取
        ];

        // 可选参数
        if (!empty($displayName)) {
            $data['displayName'] = $displayName;
        }
        if (!empty($description)) {
            $data['description'] = $description;
        }
        if (!empty($url)) {
            $data['url'] = $url;
        }
        if (!empty($command)) {
            $data['command'] = $command;
        }
        if (!empty($args)) {
            $data['args'] = is_string($args) ? json_decode($args, true) : $args;
        }
        if (!empty($env)) {
            $data['env'] = is_string($env) ? json_decode($env, true) : $env;
        }
        if (!empty($apiKey)) {
            $data['apiKey'] = $apiKey;
        }
        if (!empty($tools)) {
            $data['tools'] = is_string($tools) ? json_decode($tools, true) : $tools;
        }
        if (!empty($metadata)) {
            $data['metadata'] = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        }

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/mcp-server', $data);
            return $this->success('创建成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 更新 MCP Server
     *
     * @return mixed 返回更新结果
     */
    public function edit()
    {
        $id          = (string)input('post.id', '', 'text');         // MCP Server ID
        $displayName = (string)input('post.display_name', '', 'text'); // 显示名称
        $description = (string)input('post.description', '', 'text'); // 描述
        $transport   = (string)input('post.transport', '', 'text');  // 传输协议
        $url         = (string)input('post.url', '', 'text');        // HTTP/SSE端点地址
        $command     = (string)input('post.command', '', 'text');    // stdio命令
        $args        = (string)input('post.args', '', 'text');       // stdio参数（JSON数组）
        $env         = (string)input('post.env', '', 'text');        // 环境变量（JSON对象）
        $apiKey      = input('post.api_key', null);                   // API密钥（传null清空）
        $timeout     = (int)input('post.timeout', 0, 'intval');      // 超时时间（毫秒）
        $enabled     = input('post.enabled', null, 'bool');          // 是否启用
        $tools       = (string)input('post.tools', '', 'text');      // 允许的工具列表（JSON数组）
        $metadata    = (string)input('post.metadata', '', 'text');   // 扩展元数据（JSON对象）

        if (empty($id)) {
            return $this->error('MCP Server ID 不能为空');
        }

        $data = [];

        // 更新参数（可选）
        if (!empty($displayName)) {
            $data['displayName'] = $displayName;
        }
        if (!empty($description)) {
            $data['description'] = $description;
        }
        if (!empty($transport)) {
            $data['transport'] = $transport;
        }
        if (!empty($url)) {
            $data['url'] = $url;
        }
        if (!empty($command)) {
            $data['command'] = $command;
        }
        if (!empty($args)) {
            $data['args'] = is_string($args) ? json_decode($args, true) : $args;
        }
        if (!empty($env)) {
            $data['env'] = is_string($env) ? json_decode($env, true) : $env;
        }
        if (isset($apiKey)) {
            $data['apiKey'] = $apiKey;
        }
        if ($timeout > 0) {
            $data['timeout'] = $timeout;
        }
        if (isset($enabled)) {
            $data['enabled'] = $enabled;
        }
        if (!empty($tools)) {
            $data['tools'] = is_string($tools) ? json_decode($tools, true) : $tools;
        }
        if (!empty($metadata)) {
            $data['metadata'] = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        }

        try {
            $result = $this->muuAgent->callAdmin('PUT', '/admin/mcp-server/' . $id, $data);
            return $this->success('更新成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除 MCP Server
     *
     * @return mixed 返回删除结果
     */
    public function del()
    {
        $id = (string)input('post.id', '', 'text');  // MCP Server ID

        if (empty($id)) {
            return $this->error('MCP Server ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('DELETE', '/admin/mcp-server/' . $id);
            return $this->success('删除成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 导入 MCP Server（支持 Claude Desktop 配置格式）
     *
     * @return mixed 返回导入结果
     */
    public function import()
    {
        $mcpServers = (string)input('post.mcp_servers', '', 'text'); // Claude Desktop配置格式（JSON）

        if (empty($mcpServers)) {
            return $this->error('MCP Server 配置不能为空');
        }

        $data = [
            'mcpServers' => is_string($mcpServers) ? json_decode($mcpServers, true) : $mcpServers,
        ];

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/mcp-server/import', $data);
            return $this->success('导入成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 发现 MCP Server 工具
     *
     * @return mixed 返回工具列表
     */
    public function discoverTools()
    {
        $serverId  = (string)input('post.server_id', '', 'text');    // MCP Server ID（可选）
        $transport = (string)input('post.transport', 'http', 'text'); // 传输协议
        $url       = (string)input('post.url', '', 'text');          // HTTP/SSE端点地址
        $command   = (string)input('post.command', '', 'text');      // stdio命令
        $args      = (string)input('post.args', '', 'text');         // stdio参数（JSON数组）
        $env       = (string)input('post.env', '', 'text');          // 环境变量（JSON对象）
        $apiKey    = (string)input('post.api_key', '', 'text');      // API密钥
        $timeout   = (int)input('post.timeout', 30000, 'intval');    // 超时时间（毫秒）

        $data = [
            'transport' => $transport,
            'timeout'   => $timeout,
        ];

        if (!empty($serverId)) {
            $data['serverId'] = $serverId;
        }
        if (!empty($url)) {
            $data['url'] = $url;
        }
        if (!empty($command)) {
            $data['command'] = $command;
        }
        if (!empty($args)) {
            $data['args'] = is_string($args) ? json_decode($args, true) : $args;
        }
        if (!empty($env)) {
            $data['env'] = is_string($env) ? json_decode($env, true) : $env;
        }
        if (!empty($apiKey)) {
            $data['apiKey'] = $apiKey;
        }

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/mcp-server/discover', $data);
            return $this->success('发现成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 同步 MCP Server 工具
     *
     * @return mixed 返回同步结果
     */
    public function syncTools()
    {
        $id = (string)input('post.id', '', 'text');  // MCP Server ID

        if (empty($id)) {
            return $this->error('MCP Server ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/mcp-server/' . $id . '/sync');
            return $this->success('同步成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 测试 MCP Server 连接
     *
     * @return mixed 返回测试结果
     */
    public function testConnection()
    {
        $serverId  = (string)input('post.server_id', '', 'text');    // MCP Server ID（可选）
        $transport = (string)input('post.transport', 'http', 'text'); // 传输协议
        $url       = (string)input('post.url', '', 'text');          // HTTP/SSE端点地址
        $command   = (string)input('post.command', '', 'text');      // stdio命令
        $args      = (string)input('post.args', '', 'text');         // stdio参数（JSON数组）
        $env       = (string)input('post.env', '', 'text');          // 环境变量（JSON对象）
        $apiKey    = (string)input('post.api_key', '', 'text');      // API密钥
        $toolName  = (string)input('post.tool_name', '', 'text');    // 工具名称（可选）
        $params    = (string)input('post.params', '', 'text');       // 工具参数（JSON对象）
        $timeout   = (int)input('post.timeout', 30000, 'intval');    // 超时时间（毫秒）

        $data = [
            'transport' => $transport,
            'timeout'   => $timeout,
        ];

        if (!empty($serverId)) {
            $data['serverId'] = $serverId;
        }
        if (!empty($url)) {
            $data['url'] = $url;
        }
        if (!empty($command)) {
            $data['command'] = $command;
        }
        if (!empty($args)) {
            $data['args'] = is_string($args) ? json_decode($args, true) : $args;
        }
        if (!empty($env)) {
            $data['env'] = is_string($env) ? json_decode($env, true) : $env;
        }
        if (!empty($apiKey)) {
            $data['apiKey'] = $apiKey;
        }
        if (!empty($toolName)) {
            $data['toolName'] = $toolName;
        }
        if (!empty($params)) {
            $data['params'] = is_string($params) ? json_decode($params, true) : $params;
        }

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/mcp-server/test', $data);
            return $this->success('测试成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 测试已注册的 MCP Server 连接
     *
     * @return mixed 返回测试结果
     */
    public function testConnectionById()
    {
        $id = (string)input('post.id', '', 'text');  // MCP Server ID

        if (empty($id)) {
            return $this->error('MCP Server ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/mcp-server/' . $id . '/test');
            return $this->success('测试成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 健康检查所有 MCP Server
     *
     * @return mixed 返回健康状态
     */
    public function healthCheck()
    {
        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/mcp-server/health-check');
            return $this->success('检查完成', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 刷新 MCP Server 缓存
     *
     * @return mixed 返回刷新结果
     */
    public function refreshCache()
    {
        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/mcp-server/refresh-cache');
            return $this->success('缓存已刷新', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}