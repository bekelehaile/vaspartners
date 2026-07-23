import { z } from "zod";

export const companyProfileSchema = z.object({
  company_name: z
    .string()
    .trim()
    .min(2, "Enter the company / organisation name")
    .max(255, "Name is too long"),
  company_tin: z
    .string()
    .trim()
    .min(5, "Enter a valid TIN")
    .max(64, "TIN is too long"),
  company_phone: z
    .string()
    .trim()
    .min(9, "Enter a valid company phone number")
    .max(32, "Phone number is too long"),
  company_email: z
    .string()
    .trim()
    .min(1, "Enter a company email")
    .email("Enter a valid email address")
    .max(255, "Email is too long"),
  company_address: z
    .string()
    .trim()
    .min(5, "Enter the company address")
    .max(2000, "Address is too long"),
});

export type CompanyProfileValues = z.infer<typeof companyProfileSchema>;

export const emptyCompanyProfile: CompanyProfileValues = {
  company_name: "",
  company_tin: "",
  company_phone: "",
  company_email: "",
  company_address: "",
};
