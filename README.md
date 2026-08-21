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

### Restrição por classe de entrega
- Define, por área de entrega, com quais classes a Central do Frete trabalha
- Modo "apenas as classes selecionadas" e modo "todas, exceto as selecionadas"
- Produtos sem classe podem ser selecionados como se fossem uma classe
- Carrinho misto configurável: basta um produto atendido, ou todos precisam se enquadrar
- Carrinho bloqueado não consulta a API

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

### 3. Restringir por classe de entrega (opcional)

A seção **Restrição por classe de entrega** só aparece se a loja já tiver classes cadastradas em **WooCommerce > Configurações > Entrega > Classes de entrega**.

1. Cadastre as classes e marque cada produto na aba **Envio**
2. Nas configurações do método, escolha a regra:
   - **Todas as classes**: comportamento padrão, a Central do Frete cota tudo
   - **Apenas as classes selecionadas**: cota só o que estiver marcado
   - **Todas, exceto as selecionadas**: deixa de cotar o que estiver marcado
3. Selecione as classes. Sem nenhuma selecionada, a Central do Frete continua atendendo todas
4. Decida o carrinho misto. Com **Exigir que todos os produtos do carrinho se enquadrem** desligado, basta um produto atendido para a Central do Frete aparecer

**Atenção:** se o carrinho ficar sem nenhum método de entrega disponível, o cliente não consegue fechar o pedido. Mantenha outro método na mesma área de entrega para os produtos que você deixar de fora.

### 4. Configurar produtos

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
│   ├── class-cdf-shipping-class-rule.php # Regra de classe de entrega
│   ├── class-cdf-api-client.php      # Cliente da API
│   ├── class-cdf-cache.php           # Sistema de cache
│   ├── class-cdf-product-fields.php  # Campos no produto
│   └── class-cdf-frontend-calculator.php # Calculador na página do produto
├── assets/
│   ├── js/cdf-calculator.js          # JavaScript do calculador
│   └── css/cdf-calculator.css        # Estilos do calculador
├── templates/
│   └── product-shipping-calculator.php # Template do calculador
├── readme.txt                        # Ficha do diretório do WordPress.org
└── LICENSE                           # GPLv2
```

## Ficha do WordPress.org

Os arquivos da ficha do diretório (ícone, banner e screenshots) ficam em `.wordpress-org/`
e **não** vão no zip do plugin. No SVN eles são copiados para a pasta `assets/` do topo,
que é irmã de `trunk/` e `tags/`, não a pasta `assets/` que existe dentro do plugin.

```
.wordpress-org/
├── icon-128x128.png      # ícone da ficha
├── icon-256x256.png      # ícone em alta densidade
├── icon.svg              # versão vetorial
├── banner-772x250.png    # cabeçalho da ficha
├── banner-1544x500.png   # cabeçalho em alta densidade
├── screenshot-1.png      # conexão e exibição
├── screenshot-2.png      # restrição por classe de entrega
└── screenshot-3.png      # calculador na página do produto
```

As legendas das screenshots vivem na seção `== Screenshots ==` do `readme.txt`, na ordem
dos números dos arquivos.

## Gerando o pacote de distribuição

O zip entregue ao lojista sai do próprio git, sem os arquivos de desenvolvimento
(`.gitattributes` cuida disso) e com a pasta já nomeada como o slug:

```bash
git archive --format=zip --prefix=central-do-frete/ -o central-do-frete.zip HEAD
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

## Testes

A regra de classe de entrega é coberta por PHPUnit e roda sem WordPress.

```bash
composer install
composer test
```

Sem PHP na máquina, dá para rodar em container:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 install
docker run --rm -v "$PWD":/app -w /app php:7.4-fpm-alpine php vendor/bin/phpunit
```

## Changelog

### 3.1.0
- Restrição por classe de entrega, por área de entrega
- Calculador da página do produto respeita a restrição
- Salvar as configurações passa a invalidar o cache de tarifas do WooCommerce
- Testes automatizados da regra de classe (PHPUnit)

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
