# Central do Frete para WooCommerce

Plugin oficial para integração entre WooCommerce e [Central do Frete](https://centraldofrete.com). Cotação de frete em tempo real com múltiplas transportadoras.

## Requisitos

- WordPress 5.6+
- WooCommerce 5.0+
- PHP 7.4+

## Funcionalidades

### Cotação em tempo real
- Integração direta com a API da Central do Frete
- Múltiplas transportadoras em uma única consulta
- Suporte a tipos de carga personalizados por produto

### Calculador na página do produto
- Campo de CEP para calcular frete antes de adicionar ao carrinho
- Exibe transportadora, prazo e valor
- CEP salvo na sessão para pré-preencher no checkout

### Opções de exibição
- Mostrar todas as transportadoras
- Apenas as 3 mais baratas
- Só a mais barata e a mais rápida
- Exibir prazo de entrega
- Exibir logo da transportadora
- Ocultar opções sem coleta (Balcão)

### Cache inteligente
- Cotações cacheadas para reduzir chamadas à API
- TTL configurável (15min, 30min, 1h, 2h)
- Invalidação automática quando parâmetros mudam

### Ajustes de preço e prazo
- Dias adicionais para preparação
- Taxa de manuseio adicional
- Dimensões padrão para produtos sem medidas

### Compatibilidade
- WooCommerce HPOS (High-Performance Order Storage)
- Checkout clássico e checkout em blocos
- Plugins brasileiros de checkout (Extra Checkout Fields for Brazil, etc.)
- Suporte a CPF/CNPJ para cotações mais precisas

## Instalação

### Via WordPress Admin

1. Faça o download do plugin (arquivo .zip)
2. No painel WordPress, vá em **Plugins > Adicionar novo > Enviar plugin**
3. Selecione o arquivo zip e clique em **Instalar agora**
4. Após instalar, clique em **Ativar plugin**

### Manual

1. Extraia o conteúdo do zip na pasta `/wp-content/plugins/`
2. Ative o plugin no menu **Plugins** do WordPress

## Configuração

### 1. Criar área de entrega

1. Acesse **WooCommerce > Configurações > Entrega**
2. Crie ou edite uma área de entrega para o Brasil
3. Clique em **Adicionar método de entrega**
4. Selecione **Central do Frete**

### 2. Configurar o plugin

1. Na área de entrega, clique em **Editar** no método Central do Frete
2. Insira seu **Token de acesso** (disponível em [app.centraldofrete.com](https://app.centraldofrete.com) > Integrações > API)
3. Clique em **Salvar**
4. Clique em **Atualizar Tipos de Carga** para carregar as opções disponíveis
5. Configure as demais opções conforme sua necessidade

### 3. Configurar produtos

- Cada produto pode ter um **Tipo de Carga** específico (na aba Envio do produto)
- Produtos sem tipo de carga usam o padrão configurado no plugin
- Certifique-se de que os produtos têm peso e dimensões cadastrados

## Estrutura de arquivos

```
central-do-frete/
├── woo-central-do-frete.php          # Bootstrap do plugin
├── includes/
│   ├── class-cdf-loader.php          # Carregamento e hooks
│   ├── class-cdf-shipping-method.php # Método de envio WooCommerce
│   ├── class-cdf-api-client.php      # Cliente da API
│   ├── class-cdf-cache.php           # Sistema de cache
│   ├── class-cdf-product-fields.php  # Campos no produto
│   └── class-cdf-frontend-calculator.php # Calculador na página do produto
├── assets/
│   ├── js/cdf-calculator.js          # JavaScript do calculador
│   └── css/cdf-calculator.css        # Estilos do calculador
└── templates/
    └── product-shipping-calculator.php # Template do calculador
```

## Debug

Para diagnosticar problemas:

1. Ative o **Modo debug** nas configurações do plugin
2. Acesse **WooCommerce > Status > Logs**
3. Procure por arquivos `central-do-frete-*.log`

Os logs incluem:
- Requisições e respostas da API
- Cálculo de volumes e dimensões
- Cache hits/misses
- Erros e warnings

## Changelog

### 3.0.0
- Reescrita completa do plugin
- Sistema de cache com WordPress Transients
- Calculador de frete na página do produto
- Exibição de logo das transportadoras
- Compatibilidade com HPOS
- Interface de configuração reorganizada
- Logs estruturados

### 2.0.x
- Versão anterior (legado)

## Suporte

- [Central do Frete](https://centraldofrete.com)
- [GitHub Issues](https://github.com/central-do-frete/CentraldoFrete-WooCommerce/issues)

## Licença

GPLv2 - Veja o arquivo LICENSE para mais detalhes.
