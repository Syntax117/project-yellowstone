using System;
using System.Collections.Generic;
using System.Linq;
using System.Web;
using System.Web.Mvc;
using ProjectYellowstone.Models;
using System.Threading.Tasks;

namespace ProjectYellowstone.Controllers
{
	public class VisualiserController : Controller
	{
		public ActionResult Index()
		{
			return View();
		}
	}
}