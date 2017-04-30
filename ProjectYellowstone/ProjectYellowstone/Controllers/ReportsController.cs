using System;
using System.Collections.Generic;
using System.Linq;
using System.Web;
using System.Web.Mvc;
using ProjectYellowstone.Models;
using System.Threading.Tasks;

namespace ProjectYellowstone.Controllers
{
	public class ReportsController : Controller
	{
		private APIClient apiClient;

		//public async Task<ActionResult> Index()
		//public ActionResult Index()
		//{
			// Create APIClient, load data and return it as a model.
			//apiClient = new APIClient();

			//await apiClient.AuthenticateAsync();

			//return View(new ReportWrapper()
			//{
			//	Reports = await apiClient.GetAllReports()
			//});
		//	return View();
		//}

		//[HttpGet]
		//public async Task<string> LoadAll()
		//{
		//	if (apiClient == null)
		//	{
		//		apiClient = new APIClient();
		//		await apiClient.AuthenticateAsync();
		//	}

		//	return await apiClient.GetRawAll();
		//}

		[HttpGet]
		public async Task<string> Load(float lat, float lng, float radius)
		{
			if (apiClient == null)
			{
				apiClient = new APIClient();
				await apiClient.AuthenticateAsync();
			}

			return await apiClient.GetRawRadiusReportsAsync(lat, lng, radius);
		}

		[HttpPost]
		public async Task<JsonResult> Add(float lat, float lng)
		{
			if (apiClient == null)
			{
				apiClient = new APIClient();
				await apiClient.AuthenticateAsync();
			}

			return Json(await apiClient.SendReport(lat, lng));
		}
	}
}