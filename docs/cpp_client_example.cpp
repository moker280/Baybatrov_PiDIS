// Пример интеграции внешнего C++-приложения с АИС «СРА» по REST API (JSON).
// Сборка (Linux, Boost >= 1.75):
//   g++ -std=c++17 -O2 cpp_client_example.cpp -o cpp_client -lboost_json -lboost_system -pthread
// Запуск: сайт должен быть поднят (php -S 127.0.0.1:8000 -t public),
// токен — из config.php (security.api_token).
#include <boost/beast/core.hpp>
#include <boost/beast/http.hpp>
#include <boost/beast/version.hpp>
#include <boost/asio/connect.hpp>
#include <boost/asio/ip/tcp.hpp>
#include <boost/json.hpp>
#include <iostream>
#include <string>

namespace beast = boost::beast;
namespace http = beast::http;
namespace asio = boost::asio;
using tcp = asio::ip::tcp;

static const char* HOST = "127.0.0.1";
static const char* PORT = "8000";
static const char* API_TOKEN = "REPLACE_WITH_RANDOM_API_TOKEN"; // как в config.php

// Один HTTP-запрос к API; возвращает тело ответа.
static std::string api_request(http::verb method, const std::string& target,
                               const std::string& body = "")
{
    asio::io_context ioc;
    tcp::resolver resolver(ioc);
    beast::tcp_stream stream(ioc);
    stream.connect(resolver.resolve(HOST, PORT));

    http::request<http::string_body> req{method, target, 11};
    req.set(http::field::host, HOST);
    req.set(http::field::user_agent, "SRA-Cpp-Client/1.0");
    req.set("X-API-Token", API_TOKEN);
    if (!body.empty()) {
        req.set(http::field::content_type, "application/json");
        req.body() = body;
        req.prepare_payload();
    }
    http::write(stream, req);

    beast::flat_buffer buffer;
    http::response<http::string_body> res;
    http::read(stream, buffer, res);
    std::cout << method << " " << target << " -> " << res.result_int() << "\n";

    beast::error_code ec;
    stream.socket().shutdown(tcp::socket::shutdown_both, ec); // штатное закрытие
    return res.body();
}

int main()
{
    try {
        // 1) Чтение сводного отчёта (вызывает хранимую процедуру sp_manufacturer_report).
        const std::string report = api_request(http::verb::get, "/api.php/report/summary");
        const boost::json::value parsed = boost::json::parse(report);
        std::cout << "Отчёт по производителям:\n" << boost::json::serialize(parsed) << "\n\n";

        // 2) Создание производителя из C++-приложения (POST, JSON через Boost.JSON).
        boost::json::object manufacturer;
        manufacturer["name"] = "Завод из C++ клиента";
        manufacturer["country"] = "Россия";
        manufacturer["founded_year"] = 2001;
        manufacturer["status"] = "active";
        const std::string created =
            api_request(http::verb::post, "/api.php/manufacturers",
                        boost::json::serialize(manufacturer));
        std::cout << "Ответ на создание: " << created << "\n";

        // 3) Чтение карточки модели №1 со всеми модификациями.
        std::cout << "Карточка модели: "
                  << api_request(http::verb::get, "/api.php/models/1") << "\n";
    } catch (const std::exception& e) {
        std::cerr << "Ошибка: " << e.what() << "\n";
        return 1;
    }
    return 0;
}